<?php

namespace App\Http\Resources;

use App\Models\WhatsappTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A one-to-one chat for the reply keyboard: the contact (its target), the
 * account it came through, the latest message the contact sent and whether
 * that message still waits for an answer (nothing was sent after it).
 *
 * @mixin WhatsappTarget
 */
class WhatsappChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappTarget $target */
        $target = $this->resource;

        return [
            'friendly_id' => $target->friendly_id,
            'name' => $target->displayName(),
            'phone_number' => $target->phoneNumber(),
            'account' => [
                'friendly_id' => $target->account->friendly_id,
                'nickname' => $target->account->nickname,
            ],
            'last_received_message' => $target->lastReceivedChatMessage === null
                ? null
                : new WhatsappChatMessageResource($target->lastReceivedChatMessage),
            'awaiting_reply' => $target->lastChatMessage !== null && ! $target->lastChatMessage->from_me,
        ];
    }
}
