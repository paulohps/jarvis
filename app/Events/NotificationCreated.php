<?php

namespace App\Events;

use App\Services\Notifications\CenterNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An in-app notification was stored (US-5.1): the recipient's bell bumps
 * its count without a refresh. Broadcast on the recipient's private channel
 * (`App.Models.User.{id}`, see routes/channels.php) with the notification id,
 * its rendered title and the recipient's unread count.
 *
 * Queued apart from the notification, so a broadcasting failure never
 * retries (and duplicates) the stored notification.
 */
final class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationId,
        public readonly string $title,
        public readonly int $unreadCount,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->userId))];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array{id: string, title: string, unread_count: int}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'title' => $this->title,
            'unread_count' => $this->unreadCount,
        ];
    }

    public function broadcastQueue(): string
    {
        return CenterNotification::QUEUE;
    }

    /**
     * The private channel of a user, without the `private-` prefix.
     */
    public static function channelFor(int $userId): string
    {
        return 'App.Models.User.'.$userId;
    }
}
