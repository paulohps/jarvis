<?php

namespace App\Actions\Notifications;

use App\Models\Locale;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Templates\HtmlSanitizer;
use App\Services\Templates\TemplateError;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateValidator;
use App\Support\Localization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The super admin saves the texts of a notification type (US-5.5, ported
 * from my-finance): for each channel and language, the title, the body and
 * the action buttons.
 *
 * Nothing is saved while any text has a problem. Each title, body and button
 * label is checked against the variables the type declares, and every
 * problem says where it starts; the title is required. The body goes through
 * the allowlist sanitizer before it is stored, and is checked again
 * afterwards, since removing markup could break a condition around it. A
 * button points to one of the links the type declares — the super admin
 * never types a URL — and its empty label means the link's own.
 *
 * Only the texts that changed are written, each with its editor
 * (`updated_by_id`), which also keeps `notifications:provision` from ever
 * overwriting them. A language left without a text (null) stays without
 * one, and its readers get the English text.
 */
final readonly class SaveNotificationTemplates
{
    public const int TITLE_MAX_LENGTH = 512;

    public const int LABEL_MAX_LENGTH = 120;

    public function __construct(
        private NotificationRegistry $registry,
        private TemplateValidator $validator,
        private HtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $templates  channel slug => locale slug => array{title: string, body: string, actions: list<array{link_key: string, label: string}>}, or null for a language without a text
     * @return int how many texts were written
     *
     * @throws AuthorizationException
     * @throws ValidationException keyed `templates.<channel>.<locale>.<part>`
     */
    public function __invoke(User $actor, NotificationType $type, array $templates): int
    {
        Gate::forUser($actor)->authorize('super-admin');

        $class = $this->registry->classFor($type->slug)
            ?? throw new InvalidArgumentException(sprintf('The notification type [%s] is not registered.', $type->slug));

        $typeChannels = $type->typeChannels()->with(['channel', 'templates.locale'])->get();
        $errors = [];
        $drafts = [];

        foreach ($typeChannels as $typeChannel) {
            $channel = $typeChannel->channel->slug;

            foreach (Localization::SUPPORTED_LOCALES as $locale) {
                $draft = $templates[$channel][$locale] ?? null;

                if (! is_array($draft)) {
                    continue;
                }

                $drafts[] = [
                    $typeChannel,
                    $locale,
                    $this->clean($draft, $class, "templates.{$channel}.{$locale}", $errors),
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($drafts, $actor): int {
            $written = 0;

            foreach ($drafts as [$typeChannel, $locale, $template]) {
                if ($this->write($typeChannel, $locale, $template, $actor)) {
                    $written++;
                }
            }

            return $written;
        });
    }

    /**
     * The text as it will be stored, with its problems added to `$errors`.
     *
     * @param  array<array-key, mixed>  $draft
     * @param  class-string<CenterNotification>  $class
     * @param  array<string, list<string>>  $errors
     * @return array{title: string, body: string, actions: list<array{link_key: string, label: string}>}
     */
    private function clean(array $draft, string $class, string $field, array &$errors): array
    {
        $schema = $class::schema();
        $links = $class::links();
        $title = $this->string($draft['title'] ?? null);
        $body = $this->string($draft['body'] ?? null);
        $actions = is_array($draft['actions'] ?? null) ? array_values($draft['actions']) : [];

        if ($title === '') {
            $errors["{$field}.title"][] = (string) __('Write the title.');
        } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $errors["{$field}.title"][] = (string) __('The title can have up to :max characters.', ['max' => self::TITLE_MAX_LENGTH]);
        } else {
            $this->addProblems($errors, "{$field}.title", $this->problems($title, $schema));
        }

        $bodyProblems = $this->problems($body, $schema);

        if ($bodyProblems !== []) {
            $this->addProblems($errors, "{$field}.body", $bodyProblems);
        } else {
            $body = $this->sanitizer->sanitize($body);

            foreach ($this->problems($body, $schema) as $problem) {
                $errors["{$field}.body"][] = (string) __('Removing the formatting that is not allowed breaks the text: :problem', ['problem' => $problem]);
            }
        }

        $cleanActions = [];

        foreach ($actions as $index => $action) {
            $linkKey = is_array($action) ? $this->string($action['link_key'] ?? null) : '';
            $label = is_array($action) ? $this->string($action['label'] ?? null) : '';

            if (! array_key_exists($linkKey, $links)) {
                $errors["{$field}.actions.{$index}.link_key"][] = (string) __('Pick one of the links this notification declares.');

                continue;
            }

            if (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                $errors["{$field}.actions.{$index}.label"][] = (string) __('The label can have up to :max characters.', ['max' => self::LABEL_MAX_LENGTH]);
            } else {
                $this->addProblems($errors, "{$field}.actions.{$index}.label", $this->problems($label, $schema));
            }

            $cleanActions[] = ['link_key' => $linkKey, 'label' => $label];
        }

        return ['title' => $title, 'body' => $body, 'actions' => $cleanActions];
    }

    /**
     * The problems of a template, each with where it starts, in the reader's
     * language.
     *
     * @return list<string>
     */
    private function problems(string $template, TemplateSchema $schema): array
    {
        if ($template === '') {
            return [];
        }

        return array_map(
            static fn (TemplateError $error): string => $error->translated(),
            $this->validator->validate($template, $schema),
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $problems
     */
    private function addProblems(array &$errors, string $key, array $problems): void
    {
        foreach ($problems as $problem) {
            $errors[$key][] = $problem;
        }
    }

    /**
     * Stores the text of a language when it is new or changed.
     *
     * @param  array{title: string, body: string, actions: list<array{link_key: string, label: string}>}  $template
     */
    private function write(NotificationTypeChannel $typeChannel, string $locale, array $template, User $actor): bool
    {
        $stored = $typeChannel->templates->first(
            fn (NotificationTemplate $candidate): bool => $candidate->locale->slug === $locale,
        );

        if ($stored !== null
            && $stored->title === $template['title']
            && $stored->body === $template['body']
            && ($stored->actions ?? []) === $template['actions']) {
            return false;
        }

        $attributes = [...$template, 'updated_by_id' => $actor->id];

        if ($stored === null) {
            $typeChannel->templates()->create([...$attributes, 'locale_id' => Locale::idFor($locale)]);
        } else {
            $stored->update($attributes);
        }

        return true;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? mb_trim($value) : '';
    }
}
