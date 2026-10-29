<?php

namespace App\Http\Resources;

use App\Models\WhatsappAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the user's own WhatsApp numbers (US-7.1, US-7.2), read only in the
 * apps — connecting and reconnecting stay on the web: nickname, number,
 * connection status, last sync and the defaults of its chats (`chat_settings`:
 * read receipts, "typing…", pushes, reads on the phone). The Evolution instance is never sent.
 *
 * @mixin WhatsappAccount
 */
class WhatsappAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappAccount $account */
        $account = $this->resource;

        return [
            'friendly_id' => $account->friendly_id,
            'nickname' => $account->nickname,
            'phone_number' => $account->phone_number,
            'status' => $account->status->slug,
            'is_open' => $account->isOpen(),
            'connected_at' => $account->connected_at?->toIso8601String(),
            'disconnected_at' => $account->disconnected_at?->toIso8601String(),
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'chat_settings' => [
                'sends_read_receipts' => $account->sends_read_receipts,
                'sends_typing' => $account->sends_typing,
                'notifies_messages' => $account->notifies_messages ?? true,
                'syncs_phone_reads' => $account->syncs_phone_reads ?? true,
            ],
        ];
    }
}
