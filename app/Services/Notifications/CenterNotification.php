<?php

namespace App\Services\Notifications;

use App\Models\Locale;
use App\Models\MailService;
use App\Models\NotificationChannel;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Services\Mail\MailServiceMailer;
use App\Services\Notifications\Channels\ChannelDrivers;
use App\Services\Notifications\Mail\CenterMailMessage;
use App\Services\Push\PushMessage;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateSyntaxException;
use App\Support\Localization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Traits\Localizable;

/**
 * Base of every notification center type (Key Concept "Notification
 * center", ported from my-finance without workspaces and audiences).
 *
 * A type declares what it is (key, label, module), where it may go
 * (channels), what it may say (schema, links) and what it says by default in
 * each language; the super admin owns the wording and this class owns the
 * delivery. Send it through `NotificationCenter::dispatch()`.
 *
 * Each channel of each recipient is its own queued job on the
 * `notifications` queue, retried on failure, so a failing e-mail never holds
 * back the in-app notification (US-5.3). `via()` runs the gate when sending
 * and `shouldSend()` runs it again in the worker.
 *
 * A recipient is a `User` or, for an address without an account (the
 * invitation), an on-demand notifiable (`Notification::route('mail', ...)`),
 * written in the language set with `locale()`.
 */
#[Tries(3)]
#[Backoff(30, 120)]
#[DeleteWhenMissingModels]
abstract class CenterNotification extends Notification implements ShouldQueue
{
    use Localizable, Queueable, SerializesModels;

    public const string QUEUE = 'notifications';

    /**
     * The registry key and the `notification_types.slug`, in snake_case.
     */
    abstract public static function key(): string;

    /**
     * The name shown to the super admin and in the profile, translated.
     */
    abstract public static function label(): string;

    /**
     * What it tells the recipient, translated.
     */
    abstract public static function description(): string;

    /**
     * The module (`modules.slug`) whose users it is about, or null for a
     * notification about the account. A module type only reaches users with
     * that module switched on (US-5.4).
     */
    abstract public static function module(): ?string;

    /**
     * The channels (`notification_channels.slug`) it may be delivered
     * through, in display order; provisioning creates one row for each.
     *
     * @return list<string>
     */
    abstract public static function channels(): array;

    /**
     * The variables its templates may use.
     */
    abstract public static function schema(): TemplateSchema;

    /**
     * The destinations its actions may point to, by link key.
     *
     * @return array<string, NotificationLink>
     */
    abstract public static function links(): array;

    /**
     * What it says on a channel in a language (`locales.slug`) before the
     * super admin edits it.
     */
    abstract public static function defaults(string $channel, string $locale): ChannelDefaults;

    /**
     * The variables for one recipient, raw: the dates and amounts are written
     * out by `TemplateContextBuilder::format()` according to the schema, in
     * the recipient's language and timezone. Link URLs are built from it too.
     *
     * @return array<string, mixed>
     */
    abstract public function context(object $notifiable): array;

    /**
     * @return list<string>
     */
    final public function via(object $notifiable): array
    {
        return array_map(
            ChannelDrivers::driverFor(...),
            app(NotificationGate::class)->channelsFor($this, $notifiable),
        );
    }

    /**
     * The gate again, in the worker: switching a type, a channel or a
     * preference off also drops what is already queued, and so does a
     * recipient who lost the module or was deleted meanwhile.
     */
    final public function shouldSend(object $notifiable, string $channel): bool
    {
        $slug = ChannelDrivers::slugFor($channel);

        return $slug !== null && app(NotificationGate::class)->allows($this, $notifiable, $slug);
    }

    /**
     * @return array<string, string>
     */
    final public function viaQueues(): array
    {
        return array_fill_keys(array_values(ChannelDrivers::DRIVERS), self::QUEUE);
    }

    /**
     * The `data` of the in-app notification: rendered, never re-rendered.
     *
     * @return array{key: string, title: string, body: string, actions: list<array{label: string, url: string}>, context: array<string, mixed>}
     */
    final public function toArray(object $notifiable): array
    {
        return $this->renderFor($notifiable, NotificationChannel::DATABASE)->toArray();
    }

    final public function toMail(object $notifiable): CenterMailMessage
    {
        $preferencesUrl = $notifiable instanceof User && Route::has('profile.notifications')
            ? route('profile.notifications')
            : null;

        $message = CenterMailMessage::fromRendered($this->renderFor($notifiable, NotificationChannel::MAIL), $preferencesUrl);
        $mailService = self::mailService();

        return $mailService === null ? $message : $message->mailer(MailServiceMailer::register($mailService));
    }

    /**
     * The service the super admin picked for this type's e-mail, if it is
     * still global or still a super admin's (null: the default mailer).
     */
    public static function mailService(): ?MailService
    {
        $mailService = NotificationTypeChannel::query()
            ->whereRelation('type', 'slug', static::key())
            ->where('notification_channel_id', NotificationChannel::idFor(NotificationChannel::MAIL))
            ->with(['mailService.type', 'mailService.user'])
            ->first()
            ?->mailService;

        return $mailService?->isUsableForNotifications() ? $mailService : null;
    }

    /**
     * The text sent on WhatsApp, which shows no HTML: the title in bold
     * (`*title*`), the body as plain text with its paragraphs and line
     * breaks, then each action as "label: URL".
     */
    final public function toWhatsapp(object $notifiable): string
    {
        $rendered = $this->renderFor($notifiable, NotificationChannel::WHATSAPP);

        $parts = [
            $rendered->title !== '' ? "*{$rendered->title}*" : '',
            self::plainText($rendered->body),
            implode("\n", array_map(
                static fn (array $action): string => "{$action['label']}: {$action['url']}",
                $rendered->actions,
            )),
        ];

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * The push, which shows no HTML either: the title, the body as plain
     * text, and the URL of the first action as the deep link the app opens.
     */
    final public function toPush(object $notifiable): PushMessage
    {
        $rendered = $this->renderFor($notifiable, NotificationChannel::PUSH);

        return new PushMessage(
            $rendered->title,
            self::plainText($rendered->body),
            $rendered->actions[0]['url'] ?? null,
            $this->pushThread(),
            static::pushChannel(),
        );
    }

    /**
     * What the push belongs to, for the phones to group them (and, on
     * Android, show only the latest); null for a push of its own.
     */
    public function pushThread(): ?string
    {
        return null;
    }

    /**
     * The Android notification channel the apps created for this type, or
     * null for the default one.
     */
    public static function pushChannel(): ?string
    {
        return null;
    }

    /**
     * The language the recipient reads: a user's preference, otherwise the
     * one set on the notification, otherwise English.
     */
    final public function localeFor(object $notifiable): string
    {
        $locale = $notifiable instanceof User ? $notifiable->preferredLocale() : $this->locale;

        return Localization::normalize($locale) ?? Localization::DEFAULT_LOCALE;
    }

    /**
     * The notification written out for one recipient on one channel, in the
     * recipient's language and timezone, from the template of that language
     * (English when it has none).
     *
     * A stored template that no longer parses is reported and the type's own
     * wording is used, so a broken edit never silences a notification.
     */
    final public function renderFor(object $notifiable, string $channel): RenderedNotification
    {
        $locale = $this->localeFor($notifiable);

        return $this->withLocale($locale, function () use ($notifiable, $channel, $locale): RenderedNotification {
            $context = $this->contextFor($notifiable, $locale);
            $template = app(NotificationRegistry::class)->template(static::key(), $channel, $locale)
                ?? TemplateSettings::fromDefaults(Locale::EN, static::defaults($channel, Locale::EN));

            try {
                return $this->render($template, $context, $notifiable);
            } catch (TemplateSyntaxException $exception) {
                report($exception);

                return $this->render(TemplateSettings::fromDefaults($locale, static::defaults($channel, $locale)), $context, $notifiable);
            }
        });
    }

    /**
     * The context of the type written out for the recipient, under the
     * common variables of their calendar.
     *
     * @return array<string, mixed>
     */
    private function contextFor(object $notifiable, string $locale): array
    {
        $builder = $notifiable instanceof User
            ? TemplateContextBuilder::forUser($notifiable)
            : new TemplateContextBuilder($locale, 'UTC');

        $context = $this->context($notifiable);

        foreach (static::schema()->all() as $path => $variable) {
            $value = data_get($context, $path);

            if ($value !== null) {
                data_set($context, $path, $builder->format($variable, $value));
            }
        }

        return [...$context, ...$builder->common()];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function render(TemplateSettings $template, array $context, object $notifiable): RenderedNotification
    {
        $renderer = app(TemplateRenderer::class);

        return new RenderedNotification(
            key: static::key(),
            title: $renderer->renderText($template->title, $context),
            body: $renderer->renderHtml($template->body, $context),
            actions: $this->renderActions($template->actions, $context, $notifiable),
            context: $context,
        );
    }

    /**
     * Sanitized HTML as plain text: a line break per `<br>` and list item,
     * a blank line between paragraphs and headings, no tags, no entities.
     */
    private static function plainText(string $html): string
    {
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = (string) preg_replace('/<\/(p|h[1-6]|ul|ol|blockquote)>/i', "\n\n", $text);
        $text = (string) preg_replace('/<\/li>/i', "\n", $text);
        $text = (string) preg_replace('/<li[^>]*>/i', '• ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);

        return mb_trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * The actions whose link the type declares and applies to the recipient.
     *
     * @param  list<array{link_key: string, label: string}>  $actions
     * @param  array<string, mixed>  $context
     * @return list<array{label: string, url: string}>
     */
    private function renderActions(array $actions, array $context, object $notifiable): array
    {
        $links = static::links();
        $renderer = app(TemplateRenderer::class);
        $rendered = [];

        foreach ($actions as $action) {
            $link = $links[$action['link_key']] ?? null;
            $url = $link?->resolve($context, $notifiable);

            if ($link === null || $url === null) {
                continue;
            }

            $label = mb_trim($action['label']);

            $rendered[] = [
                'label' => $label !== '' ? $renderer->renderText($label, $context) : __($link->label),
                'url' => $url,
            ];
        }

        return $rendered;
    }
}
