<?php

namespace App\Services\Notifications;

/**
 * Read model of one `notification_types` row with its channels.
 *
 * @see NotificationRegistry
 */
final readonly class TypeSettings
{
    /**
     * @param  array<string, ChannelSettings>  $channels  by channel slug
     */
    public function __construct(
        public int $id,
        public string $key,
        public bool $isActive,
        public bool $isVisibleInProfile,
        public array $channels,
    ) {}

    /**
     * @param  array{id: int, key: string, is_active: bool, is_visible_in_profile: bool, channels: list<array<string, mixed>>}  $row
     */
    public static function fromArray(array $row): self
    {
        $channels = [];

        foreach ($row['channels'] as $channel) {
            /** @var array{id: int, channel_id: int, channel: string, is_active: bool, is_channel_active: bool, is_enabled_by_default: bool, templates: list<array{locale: string, title: string, body: string, actions: list<array{link_key: string, label: string}>}>} $channel */
            $channels[$channel['channel']] = ChannelSettings::fromArray($channel);
        }

        return new self(
            id: $row['id'],
            key: $row['key'],
            isActive: $row['is_active'],
            isVisibleInProfile: $row['is_visible_in_profile'],
            channels: $channels,
        );
    }

    public function channel(string $slug): ?ChannelSettings
    {
        return $this->channels[$slug] ?? null;
    }
}
