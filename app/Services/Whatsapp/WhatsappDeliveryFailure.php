<?php

namespace App\Services\Whatsapp;

use App\Models\DeliveryStatus;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\WhatsappMessageFailed;

/**
 * A WhatsApp delivery that will not be sent (US-7.4): marked `failed` with
 * the reason, and the account's owner told with `whatsapp_message_failed`
 * (in the app, by e-mail and by push — never through WhatsApp). Used when
 * the account is not connected and when the last attempt fails.
 */
final readonly class WhatsappDeliveryFailure
{
    public function __construct(private NotificationCenter $center) {}

    /**
     * The reason a delivery from an account that is not `open` fails.
     */
    public static function accountNotOpenMessage(WhatsappAccount $account): string
    {
        return __('The WhatsApp account :nickname is not connected (:status). Reconnect it and send again.', [
            'nickname' => $account->nickname,
            'status' => $account->status->slug,
        ]);
    }

    public function record(WhatsappDelivery $delivery, string $error): void
    {
        $delivery->forceFill([
            'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::FAILED),
            'error_message' => $error,
        ])->save();

        $this->center->dispatch(new WhatsappMessageFailed($delivery), $delivery->account->user);
    }
}
