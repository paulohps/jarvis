<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationChannel;

/**
 * The channels the notification center can deliver through, by the slug of
 * their `notification_channels` row, and the Laravel channel that does it
 * (ported from my-finance).
 *
 * The in-app channel is our own class (it also stores the type and
 * broadcasts to the bell), so the framework's `database` driver keeps its
 * behavior for anything else; so are the push channel, which sends
 * through FCM, and the WhatsApp channel, which sends through the WhatsApp
 * service. A channel without an entry here is never delivered, whatever
 * the database says.
 */
final class ChannelDrivers
{
    /**
     * Slug => Laravel channel (class name or driver name), in display order.
     *
     * @var array<string, string>
     */
    public const array DRIVERS = [
        NotificationChannel::DATABASE => DatabaseChannel::class,
        NotificationChannel::MAIL => 'mail',
        NotificationChannel::PUSH => PushChannel::class,
        NotificationChannel::WHATSAPP => WhatsappChannel::class,
    ];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::DRIVERS);
    }

    public static function isImplemented(string $slug): bool
    {
        return array_key_exists($slug, self::DRIVERS);
    }

    public static function driverFor(string $slug): string
    {
        return self::DRIVERS[$slug];
    }

    /**
     * The slug of a Laravel channel, as `shouldSend()` receives it, or null
     * for a channel the center does not deliver through.
     */
    public static function slugFor(string $driver): ?string
    {
        $slug = array_search($driver, self::DRIVERS, true);

        return is_string($slug) ? $slug : null;
    }
}
