<?php

namespace App\Services\Notifications;

use App\Models\Locale;

/**
 * Read model of one `notification_type_channels` row, with its channel's
 * platform switch and its templates by language.
 *
 * @see NotificationRegistry
 */
final readonly class ChannelSettings
{
    /**
     * @param  string  $channel  the `notification_channels` slug
     * @param  bool  $isActive  the switch of the channel on this type
     * @param  bool  $isChannelActive  the switch of the channel on the platform
     * @param  array<string, TemplateSettings>  $templates  by locale slug
     */
    public function __construct(
        public int $id,
        public int $channelId,
        public string $channel,
        public bool $isActive,
        public bool $isChannelActive,
        public bool $isEnabledByDefault,
        public array $templates,
    ) {}

    /**
     * @param  array{id: int, channel_id: int, channel: string, is_active: bool, is_channel_active: bool, is_enabled_by_default: bool, templates: list<array{locale: string, title: string, body: string, actions: list<array{link_key: string, label: string}>}>}  $row
     */
    public static function fromArray(array $row): self
    {
        $templates = [];

        foreach ($row['templates'] as $template) {
            $templates[$template['locale']] = TemplateSettings::fromArray($template);
        }

        return new self(
            id: $row['id'],
            channelId: $row['channel_id'],
            channel: $row['channel'],
            isActive: $row['is_active'],
            isChannelActive: $row['is_channel_active'],
            isEnabledByDefault: $row['is_enabled_by_default'],
            templates: $templates,
        );
    }

    /**
     * Whether it may deliver: switched on for the type and on the platform.
     */
    public function isUsable(): bool
    {
        return $this->isActive && $this->isChannelActive;
    }

    /**
     * The template of the language, or the English one when the language
     * has none.
     */
    public function template(string $locale): ?TemplateSettings
    {
        return $this->templates[$locale] ?? $this->templates[Locale::EN] ?? null;
    }
}
