<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\Channels\ChannelDrivers;
use App\Services\Notifications\Channels\PushChannel;
use App\Services\Notifications\Channels\WhatsappChannel;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Decides whether a recipient gets a notification on a channel (US-5.3,
 * Workflow 5, ported from my-finance without workspaces). Every step can only
 * say no, in this order:
 *
 * 1. the recipient may receive it: a user who is not deleted nor deactivated
 *    and, for a module type, has the module (`hasModule`) — by push, only
 *    with FCM configured and a device with a push token; on WhatsApp, only
 *    with a number picked and an account to send from (their own or the
 *    platform's); an address without
 *    an account only for an account type, on a channel it is routed to,
 *    never in the app, by push nor on WhatsApp;
 * 2. the type is provisioned and active;
 * 3. the channel is delivered by the center, switched on on the platform and
 *    on the type;
 * 4. there is a template in the recipient's language or in English;
 * 5. the user's preference — or, without one, the type × channel default —
 *    is on.
 *
 * It runs in `via()` when sending and again in `shouldSend()` in the worker.
 * Preferences are memoized; `preload()` reads those of many users in one
 * query. Bound as scoped, so the memo never outlives a request or job.
 */
final class NotificationGate
{
    /**
     * @var array<int, array<string, bool>> user id => "typeId:channelId" => enabled
     */
    private array $preferences = [];

    public function __construct(private readonly NotificationRegistry $registry) {}

    /**
     * The channels (slugs) the notification goes through for the recipient.
     *
     * @return list<string>
     */
    public function channelsFor(CenterNotification $notification, object $notifiable): array
    {
        return array_values(array_filter(
            ChannelDrivers::slugs(),
            fn (string $channel): bool => $this->allows($notification, $notifiable, $channel),
        ));
    }

    public function allows(CenterNotification $notification, object $notifiable, string $channel): bool
    {
        return $this->mayReceive($notification, $notifiable, $channel)
            && $this->isSwitchedOn($notification::key(), $channel, $notifiable, $notification->localeFor($notifiable));
    }

    /**
     * Whether the recipient may get the type on the channel at all, whatever
     * the settings say.
     */
    public function mayReceive(CenterNotification $notification, object $notifiable, string $channel): bool
    {
        if ($notifiable instanceof User) {
            if ($notifiable->trashed() || $notifiable->isDeactivated()) {
                return false;
            }

            if ($channel === NotificationChannel::PUSH && ! PushChannel::canReach($notifiable)) {
                return false;
            }

            if ($channel === NotificationChannel::WHATSAPP && WhatsappChannel::accountFor($notifiable) === null) {
                return false;
            }

            $module = $notification::module();

            return $module === null || $notifiable->hasModule($module);
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            return $notification::module() === null
                && ! in_array($channel, [NotificationChannel::DATABASE, NotificationChannel::PUSH, NotificationChannel::WHATSAPP], true)
                && ChannelDrivers::isImplemented($channel)
                && filled($notifiable->routeNotificationFor(ChannelDrivers::driverFor($channel)));
        }

        return false;
    }

    /**
     * The switches of the type and the channel, the template of the
     * language, then the user's choice or the type × channel default. An
     * address without an account has no preferences and gets the default.
     */
    public function isSwitchedOn(string $key, string $channel, object $notifiable, string $locale): bool
    {
        if (! ChannelDrivers::isImplemented($channel)) {
            return false;
        }

        $settings = $this->registry->settingsFor($key);

        if ($settings === null || ! $settings->isActive) {
            return false;
        }

        $channelSettings = $settings->channel($channel);

        if ($channelSettings === null || ! $channelSettings->isUsable() || $channelSettings->template($locale) === null) {
            return false;
        }

        if (! $notifiable instanceof User) {
            return $channelSettings->isEnabledByDefault;
        }

        return $this->preference($notifiable, $settings->id, $channelSettings->channelId)
            ?? $channelSettings->isEnabledByDefault;
    }

    /**
     * The user's stored choice, or null when they never made one.
     */
    public function preference(User $user, int $typeId, int $channelId): ?bool
    {
        $this->preload([$user]);

        return $this->preferences[$user->id][$typeId.':'.$channelId] ?? null;
    }

    /**
     * Reads the stored choices of the users not read yet, in one query.
     *
     * @param  iterable<User>  $users
     */
    public function preload(iterable $users): void
    {
        $ids = [];

        foreach ($users as $user) {
            if (! array_key_exists($user->id, $this->preferences)) {
                $ids[$user->id] = $user->id;
                // A user without rows must not be queried again.
                $this->preferences[$user->id] = [];
            }
        }

        if ($ids === []) {
            return;
        }

        NotificationPreference::query()
            ->whereIn('user_id', array_values($ids))
            ->get(['user_id', 'notification_type_id', 'notification_channel_id', 'is_enabled'])
            ->each(function (NotificationPreference $preference): void {
                $key = $preference->notification_type_id.':'.$preference->notification_channel_id;

                $this->preferences[$preference->user_id][$key] = $preference->is_enabled;
            });
    }

    /**
     * Forget the user's memoized choices, so a change applies at once.
     */
    public function forget(User $user): void
    {
        unset($this->preferences[$user->id]);
    }
}
