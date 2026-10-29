<?php

namespace App\Services\Notifications\Channels;

use App\Events\NotificationCreated;
use App\Models\Notification as StoredNotification;
use App\Models\User;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Notifications\Channels\DatabaseChannel as BaseDatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The in-app channel of the notification center (US-5.1): Laravel's
 * database channel plus the type of the notification, so the bell and the
 * history can filter without opening `data`.
 *
 * Each stored notification is announced on the recipient's private channel
 * (`NotificationCreated`) with its rendered title and the new unread count,
 * so the bell updates without a refresh.
 */
final class DatabaseChannel extends BaseDatabaseChannel
{
    public function __construct(private readonly NotificationRegistry $registry) {}

    /**
     * @return StoredNotification
     */
    public function send($notifiable, Notification $notification)
    {
        /** @var StoredNotification $stored */
        $stored = parent::send($notifiable, $notification);

        if ($notifiable instanceof User) {
            NotificationCreated::dispatch(
                $notifiable->id,
                $stored->id,
                (string) ($stored->data['title'] ?? ''),
                $notifiable->unreadNotifications()->count(),
            );
        }

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if (! $notification instanceof CenterNotification) {
            return $payload;
        }

        return [
            ...$payload,
            'notification_type_id' => $this->registry->settingsFor($notification::key())?->id,
        ];
    }
}
