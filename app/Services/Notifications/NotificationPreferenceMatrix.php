<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Notifications\Channels\ChannelDrivers;
use App\Services\Notifications\Channels\WhatsappChannel;

/**
 * The type × channel matrix of a user's profile (US-3.3), copied from
 * my-finance: the types switched on and visible in the profile that the user
 * may receive (a module type only with the module), each with the channels
 * the registry offers (delivered by the center, switched on on the platform
 * and on the type).
 *
 * Each cell holds the user's choice or, without one, the type × channel
 * default, as `NotificationGate` decides. The WhatsApp channel can only be
 * switched on once the user picked the number it goes to and there is an
 * account to send it from, their own or the platform's
 * (`isChannelAvailableFor`).
 */
final readonly class NotificationPreferenceMatrix
{
    public function __construct(
        private NotificationRegistry $registry,
        private NotificationGate $gate,
    ) {}

    /**
     * Whether the user may switch the channel on at all.
     */
    public static function isChannelAvailableFor(User $user, string $channel): bool
    {
        if ($channel === NotificationChannel::WHATSAPP) {
            return WhatsappChannel::accountFor($user) !== null;
        }

        return true;
    }

    /**
     * Type key => the channel slugs the user may set a preference for.
     *
     * @return array<string, list<string>>
     */
    public function cellsFor(User $user): array
    {
        $cells = [];

        foreach ($this->registry->preferenceMatrix() as $key => $channels) {
            $class = $this->registry->classFor($key);
            $module = $class === null ? null : $class::module();

            if ($module === null || $user->hasModule($module)) {
                $cells[$key] = $channels;
            }
        }

        return $cells;
    }

    /**
     * The rows of the matrix, by type label.
     *
     * @return list<array{key: string, label: string, description: string, channels: array<string, array{isEnabled: bool, isDefault: bool, isAvailable: bool}>}>
     */
    public function rowsFor(User $user): array
    {
        $rows = [];

        foreach ($this->cellsFor($user) as $key => $channels) {
            /** @var class-string<CenterNotification> $class */
            $class = $this->registry->classFor($key);
            /** @var TypeSettings $settings */
            $settings = $this->registry->settingsFor($key);

            $cells = [];

            foreach ($channels as $channel) {
                /** @var ChannelSettings $channelSettings */
                $channelSettings = $settings->channel($channel);
                $choice = $this->gate->preference($user, $settings->id, $channelSettings->channelId);
                $isAvailable = self::isChannelAvailableFor($user, $channel);

                $cells[$channel] = [
                    'isEnabled' => $isAvailable && ($choice ?? $channelSettings->isEnabledByDefault),
                    'isDefault' => $choice === null,
                    'isAvailable' => $isAvailable,
                ];
            }

            $rows[] = [
                'key' => $key,
                'label' => $class::label(),
                'description' => $class::description(),
                'channels' => $cells,
            ];
        }

        usort($rows, static fn (array $first, array $second): int => strnatcasecmp($first['label'], $second['label']));

        return $rows;
    }

    /**
     * The channels that have at least one cell, in the center's order: the
     * columns of the matrix.
     *
     * @return list<string>
     */
    public function columnsFor(User $user): array
    {
        $used = array_merge([], ...array_values($this->cellsFor($user)));

        return array_values(array_filter(
            ChannelDrivers::slugs(),
            static fn (string $slug): bool => in_array($slug, $used, true),
        ));
    }
}
