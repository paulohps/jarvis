<?php

namespace App\Services\Notifications;

/**
 * Read model of one `notification_templates` row.
 *
 * @see NotificationRegistry
 */
final readonly class TemplateSettings
{
    /**
     * @param  list<array{link_key: string, label: string}>  $actions
     */
    public function __construct(
        public string $locale,
        public string $title,
        public string $body,
        public array $actions,
    ) {}

    /**
     * @param  array{locale: string, title: string, body: string, actions: list<array{link_key: string, label: string}>}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['locale'], $row['title'], $row['body'], $row['actions']);
    }

    /**
     * The type's own wording, for when no stored template can be used.
     */
    public static function fromDefaults(string $locale, ChannelDefaults $defaults): self
    {
        return new self($locale, $defaults->title, $defaults->body, $defaults->actions);
    }
}
