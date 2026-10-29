<?php

namespace App\Services\Notifications\Channels;

use App\Actions\Whatsapp\SendWhatsAppMessage;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use App\Services\Notifications\CenterNotification;
use Illuminate\Notifications\Notification;

/**
 * The WhatsApp channel of the notification center (US-3.3, US-5.3): the
 * notification written as plain text and sent through the WhatsApp service
 * (`SendWhatsAppMessage`, `source = notification`) to the number the user
 * picked (`whatsapp_notification_phone`), from one of their own accounts
 * when they picked one (`users.whatsapp_notification_account_id`) or else
 * from the platform's account, the one the super admin picked on the
 * channel (`notification_channels.whatsapp_account_id`). The platform's
 * account only sends to a number the user verified
 * (`whatsapp_notification_phone_verified_at`), so it never messages a
 * stranger's number on anyone's behalf.
 *
 * Without a number, or without an account to send from (none of the user's
 * own and no platform account whose owner is still a super admin), there is
 * nothing to send: the gate leaves the channel out (`accountFor()`), and
 * the channel itself sends nothing.
 */
final readonly class WhatsappChannel
{
    public function __construct(private SendWhatsAppMessage $send) {}

    /**
     * The account the user's WhatsApp notifications go from, when the user
     * picked a destination number: the one of their own accounts they
     * picked or, without one, the platform's account once the number is
     * verified.
     */
    public static function accountFor(User $user): ?WhatsappAccount
    {
        if (blank($user->whatsapp_notification_phone)) {
            return null;
        }

        if ($user->whatsapp_notification_account_id !== null) {
            $account = $user->whatsappNotificationAccount;

            if ($account?->user_id === $user->id) {
                return $account;
            }
        }

        return $user->hasVerifiedWhatsappNotificationPhone() ? self::platformAccount() : null;
    }

    /**
     * The account the super admin picked for the platform's WhatsApp
     * notifications, while its owner is still a super admin.
     */
    public static function platformAccount(): ?WhatsappAccount
    {
        $accountId = NotificationChannel::bySlug(NotificationChannel::WHATSAPP)->whatsapp_account_id;

        if ($accountId === null) {
            return null;
        }

        $account = WhatsappAccount::query()->with('user')->find($accountId);

        return $account?->isUsableForNotifications() ? $account : null;
    }

    public function send(object $notifiable, Notification $notification): ?WhatsappDelivery
    {
        if (! $notifiable instanceof User || ! $notification instanceof CenterNotification) {
            return null;
        }

        $account = self::accountFor($notifiable);

        if ($account === null) {
            return null;
        }

        return ($this->send)(
            $account,
            WhatsappTarget::forNumber($account, (string) $notifiable->whatsapp_notification_phone),
            $notification->toWhatsapp($notifiable),
            WhatsappDeliverySource::NOTIFICATION,
        );
    }
}
