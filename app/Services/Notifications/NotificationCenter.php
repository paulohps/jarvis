<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The only way a notification center type is sent (Workflow 5, ported from
 * my-finance).
 *
 * It drops what can be decided once for everyone — a type that is not
 * provisioned or switched off, deleted or deactivated users, users without
 * the module of a module type — reads the modules and the preferences of
 * the remaining users in one query each, and hands them to Laravel, which
 * asks the gate for each one's channels and queues one job per recipient per
 * channel on the `notifications` queue.
 */
final readonly class NotificationCenter
{
    public function __construct(
        private NotificationRegistry $registry,
        private NotificationGate $gate,
    ) {}

    /**
     * @param  User|AnonymousNotifiable|iterable<User|AnonymousNotifiable>  $recipients  users, or on-demand addresses for an account type
     */
    public function dispatch(CenterNotification $notification, User|AnonymousNotifiable|iterable $recipients): void
    {
        $settings = $this->registry->settingsFor($notification::key());

        if ($settings === null || ! $settings->isActive) {
            return;
        }

        $recipients = $recipients instanceof User || $recipients instanceof AnonymousNotifiable
            ? new Collection([$recipients])
            : new Collection($recipients);

        $users = new EloquentCollection($recipients
            ->filter(fn (mixed $recipient): bool => $recipient instanceof User && ! $recipient->trashed() && ! $recipient->isDeactivated())
            ->unique(fn (User $user): int => $user->id)
            ->values()
            ->all());

        $module = $notification::module();

        if ($module !== null) {
            $users->loadMissing(['memberships.modules', 'memberships.workspace']);
            $users = $users->filter(fn (User $user): bool => $user->hasModule($module))->values();
        }

        $this->gate->preload($users);

        $notifiables = $users->toBase()->concat(
            $recipients->filter(fn (mixed $recipient): bool => $recipient instanceof AnonymousNotifiable && $module === null),
        )->values();

        if ($notifiables->isEmpty()) {
            return;
        }

        Notification::send($notifiables, $notification);
    }
}
