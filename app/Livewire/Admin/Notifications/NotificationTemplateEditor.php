<?php

namespace App\Livewire\Admin\Notifications;

use App\Actions\Notifications\SaveNotificationTemplates;
use App\Actions\Templates\PreviewTemplate;
use App\Models\Locale;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Templates\TemplateFormat;
use App\Services\Templates\TemplatePreview;
use App\Services\Templates\TemplateSchema;
use App\Support\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The texts of one notification type in the admin area (US-5.5, ported from
 * my-finance): a tab per channel and language, each with the title, the body
 * and the action buttons, written in `x-ui.template-editor` with the
 * variables the type declares, beside a preview rendered with the schema's
 * sample data (`PreviewTemplate`). A language without a text is marked — its
 * readers get the English one — and can be started from the type's default
 * wording.
 *
 * Every tab is kept on screen until "Save", which checks them all at once
 * (`SaveNotificationTemplates`); when something is wrong nothing is saved and
 * the first tab with a problem opens.
 *
 * @property-read NotificationType $type
 * @property-read class-string<CenterNotification> $notificationClass
 * @property-read TemplateSchema $schema
 * @property-read array<string, string> $channels
 * @property-read array<string, string> $links
 * @property-read NotificationTemplate|null $storedTemplate
 * @property-read TemplatePreview|null $titlePreview
 * @property-read TemplatePreview|null $bodyPreview
 */
#[Layout('layouts::app-shell')]
class NotificationTemplateEditor extends Component
{
    /**
     * The key of the type (`notification_types.slug`).
     */
    #[Locked]
    public string $typeKey = '';

    /**
     * The channel of the open tab.
     */
    #[Locked]
    public string $channel = '';

    /**
     * The language of the open tab.
     */
    #[Locked]
    public string $locale = Locale::EN;

    /**
     * @var array<string, array<string, array{title: string, body: string, actions: list<array{link_key: string, label: string}>}|null>> channel slug => locale slug => text, null for a language without one
     */
    public array $templates = [];

    /**
     * What the last "Save" did, shown above the editor.
     */
    public ?string $statusMessage = null;

    public function mount(NotificationType $notificationType): void
    {
        abort_unless($this->actor()->isSuperAdmin(), 403);
        abort_if(app(NotificationRegistry::class)->classFor($notificationType->slug) === null, 404);

        $this->typeKey = $notificationType->slug;

        $this->readTemplates();

        $requested = request()->query('channel');
        $this->channel = is_string($requested) && array_key_exists($requested, $this->channels)
            ? $requested
            : (string) array_key_first($this->channels);
    }

    #[Computed]
    public function type(): NotificationType
    {
        return NotificationType::query()
            ->where('slug', $this->typeKey)
            ->with(['typeChannels.channel', 'typeChannels.templates.locale', 'typeChannels.templates.updatedBy'])
            ->firstOrFail();
    }

    /**
     * @return class-string<CenterNotification>
     */
    #[Computed]
    public function notificationClass(): string
    {
        $class = app(NotificationRegistry::class)->classFor($this->typeKey);

        abort_if($class === null, 404);

        return $class;
    }

    #[Computed]
    public function schema(): TemplateSchema
    {
        return $this->notificationClass::schema();
    }

    /**
     * The channels the type has a row for, in the type's order.
     *
     * @return array<string, string> slug => label
     */
    #[Computed]
    public function channels(): array
    {
        $channels = [];

        foreach ($this->notificationClass::channels() as $slug) {
            $typeChannel = $this->typeChannelOf($slug);

            if ($typeChannel !== null) {
                $channels[$slug] = __($typeChannel->channel->name);
            }
        }

        return $channels;
    }

    /**
     * The languages of the tabs, each named in its own language.
     *
     * @return array<string, string>
     */
    public function locales(): array
    {
        return Localization::NAMES;
    }

    /**
     * The links a button may point to, with their default label.
     *
     * @return array<string, string> link key => label
     */
    #[Computed]
    public function links(): array
    {
        $links = [];

        foreach ($this->notificationClass::links() as $key => $link) {
            $links[$key] = __($link->label);
        }

        return $links;
    }

    /**
     * The stored text of the open tab, for who edited it last.
     */
    #[Computed]
    public function storedTemplate(): ?NotificationTemplate
    {
        return $this->typeChannelOf($this->channel)?->templates->first(
            fn (NotificationTemplate $template): bool => $template->locale->slug === $this->locale,
        );
    }

    #[Computed]
    public function titlePreview(): ?TemplatePreview
    {
        return $this->preview('title', TemplateFormat::Text);
    }

    #[Computed]
    public function bodyPreview(): ?TemplatePreview
    {
        return $this->preview('body', TemplateFormat::Html);
    }

    public function isMissing(string $channel, string $locale): bool
    {
        return array_key_exists($channel, $this->templates) && $this->templates[$channel][$locale] === null;
    }

    public function selectTab(string $channel, string $locale): void
    {
        if (! array_key_exists($channel, $this->channels) || ! Localization::isSupported($locale)) {
            return;
        }

        $this->channel = $channel;
        $this->locale = $locale;
    }

    /**
     * Starts the text of a language that has none from the type's default
     * wording in that language. It is stored on "Save".
     */
    public function writeTranslation(): void
    {
        if (! $this->isMissing($this->channel, $this->locale)) {
            return;
        }

        $this->templates[$this->channel][$this->locale] = $this->defaultTemplate($this->channel, $this->locale);
    }

    public function addAction(): void
    {
        if (! is_array($this->templates[$this->channel][$this->locale] ?? null) || $this->links === []) {
            return;
        }

        $this->templates[$this->channel][$this->locale]['actions'][] = [
            'link_key' => (string) array_key_first($this->links),
            'label' => '',
        ];
    }

    public function removeAction(int $index): void
    {
        $template = $this->templates[$this->channel][$this->locale] ?? null;

        if (! is_array($template) || ! isset($template['actions'][$index])) {
            return;
        }

        unset($template['actions'][$index]);
        $template['actions'] = array_values($template['actions']);

        $this->templates[$this->channel][$this->locale] = $template;
    }

    public function save(SaveNotificationTemplates $save): void
    {
        $this->resetErrorBag();
        $this->statusMessage = null;

        try {
            $written = $save($this->actor(), $this->type, $this->templates);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->openFirstTabWithProblems(array_keys($exception->errors()));

            return;
        }

        unset($this->type, $this->storedTemplate);
        $this->readTemplates();

        $this->statusMessage = trans_choice('{0} Nothing changed.|{1} :count text saved; the next notifications already use it.|[2,*] :count texts saved; the next notifications already use them.', $written);
    }

    public function render(): View
    {
        return view('livewire.admin.notifications.notification-template-editor')
            ->title($this->notificationClass::label());
    }

    /**
     * The open tab rendered with the schema's sample data; the English text
     * when the language has none, as its readers get it.
     */
    private function preview(string $part, TemplateFormat $format): ?TemplatePreview
    {
        if (! array_key_exists($this->channel, $this->templates)) {
            return null;
        }

        $template = $this->templates[$this->channel][$this->locale]
            ?? $this->templates[$this->channel][Locale::EN]
            ?? $this->defaultTemplate($this->channel, Locale::EN);

        $text = is_string($template[$part] ?? null) ? $template[$part] : '';

        return app(PreviewTemplate::class)($this->schema, $text, $this->actor(), $format);
    }

    /**
     * Puts the stored texts on screen, one per channel and language.
     */
    private function readTemplates(): void
    {
        $this->templates = [];

        foreach (array_keys($this->channels) as $channel) {
            $typeChannel = $this->typeChannelOf($channel);

            foreach (Localization::SUPPORTED_LOCALES as $locale) {
                $template = $typeChannel?->templates->first(
                    fn (NotificationTemplate $candidate): bool => $candidate->locale->slug === $locale,
                );

                $this->templates[$channel][$locale] = $template === null ? null : [
                    'title' => $template->title,
                    'body' => $template->body,
                    'actions' => $this->normalizeActions($template->actions ?? []),
                ];
            }
        }
    }

    /**
     * The type's own wording on a channel in a language.
     *
     * @return array{title: string, body: string, actions: list<array{link_key: string, label: string}>}
     */
    private function defaultTemplate(string $channel, string $locale): array
    {
        $defaults = $this->notificationClass::defaults($channel, $locale);

        return [
            'title' => $defaults->title,
            'body' => $defaults->body,
            'actions' => $this->normalizeActions($defaults->actions),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $actions
     * @return list<array{link_key: string, label: string}>
     */
    private function normalizeActions(array $actions): array
    {
        return array_values(array_map(
            static fn (mixed $action): array => [
                'link_key' => is_array($action) && is_string($action['link_key'] ?? null) ? $action['link_key'] : '',
                'label' => is_array($action) && is_string($action['label'] ?? null) ? $action['label'] : '',
            ],
            $actions,
        ));
    }

    /**
     * @param  list<string>  $keys  `templates.<channel>.<locale>.<part>`
     */
    private function openFirstTabWithProblems(array $keys): void
    {
        foreach ($keys as $key) {
            [, $channel, $locale] = array_pad(explode('.', $key, 4), 3, '');

            if (array_key_exists($channel, $this->channels) && Localization::isSupported($locale)) {
                $this->channel = $channel;
                $this->locale = $locale;

                return;
            }
        }
    }

    private function typeChannelOf(string $slug): ?NotificationTypeChannel
    {
        return $this->type->typeChannels->first(
            fn (NotificationTypeChannel $typeChannel): bool => $typeChannel->channel->slug === $slug,
        );
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
