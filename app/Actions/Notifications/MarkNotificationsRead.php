<?php

namespace App\Actions\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Marks the user's in-app notifications read (US-5.1): one, by id, or all
 * the unread ones. Only ever through the user's own relation, so the
 * notification of someone else is not found (404) and stays as it was.
 */
final readonly class MarkNotificationsRead
{
    /**
     * @param  string|null  $notificationId  null marks every unread notification
     * @return int how many notifications became read
     *
     * @throws ModelNotFoundException<Notification> when the notification is not the user's
     */
    public function __invoke(User $user, ?string $notificationId = null): int
    {
        if ($notificationId === null) {
            return $user->unreadNotifications()->update(['read_at' => now()]);
        }

        // Ids are UUIDs: anything else is not found, never a query error.
        if (! Str::isUuid($notificationId)) {
            throw (new ModelNotFoundException)->setModel(Notification::class, [$notificationId]);
        }

        /** @var Notification $notification */
        $notification = $user->notifications()->whereKey($notificationId)->firstOrFail();

        if ($notification->read_at !== null) {
            return 0;
        }

        $notification->markAsRead();

        return 1;
    }
}
