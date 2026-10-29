<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Services\Notifications\Channels\ChannelDrivers;
use App\Support\NotificationSettingsCache;

/**
 * The notification types and their settings (ported from my-finance).
 *
 * The classes in `config/notifications.php` say what exists and what it may
 * say; the `notification_types` rows, with their channels and templates, say
 * how the super admin configured it. A registered type without a row is
 * "not provisioned" and is never sent.
 *
 * The rows are cached as plain arrays (never models) through
 * `NotificationSettingsCache`, which `NotificationSettingsObserver` flushes
 * whenever a type, a channel or a template is saved or deleted through its
 * model; a bulk update must call `flush()` itself.
 */
final readonly class NotificationRegistry
{
    public function __construct(private NotificationSettingsCache $settingsCache) {}

    /**
     * @return list<class-string<CenterNotification>>
     */
    public function classes(): array
    {
        /** @var list<class-string<CenterNotification>> $classes */
        $classes = array_values(array_unique(config('notifications.types', [])));

        return $classes;
    }

    /**
     * @return array<string, class-string<CenterNotification>> key => class
     */
    public function map(): array
    {
        $map = [];

        foreach ($this->classes() as $class) {
            $map[$class::key()] = $class;
        }

        return $map;
    }

    /**
     * @return class-string<CenterNotification>|null
     */
    public function classFor(string $key): ?string
    {
        return $this->map()[$key] ?? null;
    }

    /**
     * The settings of the registered types that were provisioned.
     *
     * @return array<string, TypeSettings> by key
     */
    public function settings(): array
    {
        /** @var array<string, array{id: int, key: string, is_active: bool, is_visible_in_profile: bool, channels: list<array<string, mixed>>}> $rows */
        $rows = $this->settingsCache->remember(fn (): array => $this->readRows());

        return array_map(
            TypeSettings::fromArray(...),
            array_intersect_key($rows, $this->map()),
        );
    }

    /**
     * The settings of a type, or null when it is not registered or not
     * provisioned.
     */
    public function settingsFor(string $key): ?TypeSettings
    {
        return $this->settings()[$key] ?? null;
    }

    public function isProvisioned(string $key): bool
    {
        return $this->settingsFor($key) !== null;
    }

    /**
     * The template of a type on a channel in a language, or in English when
     * that language has none; null when the channel has no template at all.
     */
    public function template(string $key, string $channel, string $locale): ?TemplateSettings
    {
        return $this->settingsFor($key)?->channel($channel)?->template($locale);
    }

    /**
     * What the profile preferences offer (US-3.3, US-5.5): for each type
     * switched on and visible in the profile, the channels the center
     * delivers through that are switched on on the platform and on the type.
     * A channel switched off on the platform leaves everyone's preferences.
     *
     * @return array<string, list<string>> type key => channel slugs
     */
    public function preferenceMatrix(): array
    {
        $matrix = [];

        foreach ($this->settings() as $key => $type) {
            if (! $type->isActive || ! $type->isVisibleInProfile) {
                continue;
            }

            $channels = array_values(array_filter(
                ChannelDrivers::slugs(),
                fn (string $slug): bool => $type->channel($slug)?->isUsable() ?? false,
            ));

            if ($channels !== []) {
                $matrix[$key] = $channels;
            }
        }

        return $matrix;
    }

    /**
     * Drop the cached settings, so a change applies from the next read on.
     */
    public function flush(): void
    {
        $this->settingsCache->flush();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readRows(): array
    {
        return NotificationType::query()
            ->with(['typeChannels.channel', 'typeChannels.templates.locale'])
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (NotificationType $type): array => [$type->slug => [
                'id' => $type->id,
                'key' => $type->slug,
                'is_active' => $type->is_active,
                'is_visible_in_profile' => $type->is_visible_in_profile,
                'channels' => $type->typeChannels
                    ->map(fn (NotificationTypeChannel $typeChannel): array => [
                        'id' => $typeChannel->id,
                        'channel_id' => $typeChannel->notification_channel_id,
                        'channel' => $typeChannel->channel->slug,
                        'is_active' => $typeChannel->is_active,
                        'is_channel_active' => $typeChannel->channel->is_active,
                        'is_enabled_by_default' => $typeChannel->is_enabled_by_default,
                        'templates' => $typeChannel->templates
                            ->map(fn (NotificationTemplate $template): array => [
                                'locale' => $template->locale->slug,
                                'title' => $template->title,
                                'body' => $template->body,
                                'actions' => $template->actions ?? [],
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ]])
            ->all();
    }
}
