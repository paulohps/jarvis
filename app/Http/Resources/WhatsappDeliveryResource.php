<?php

namespace App\Http\Resources;

use App\Models\WhatsappDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message sent to one target, from the history (US-7.5): when, from
 * which account, to whom, the text as it went out, what sent it (`source`)
 * and its status, with the error of a failed one. A delivery has no public
 * id: it is only listed.
 *
 * @mixin WhatsappDelivery
 */
class WhatsappDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappDelivery $delivery */
        $delivery = $this->resource;

        return [
            'account' => [
                'friendly_id' => $delivery->account->friendly_id,
                'nickname' => $delivery->account->nickname,
            ],
            'target' => new WhatsappTargetResource($delivery->target),
            'message_id' => $delivery->message?->friendly_id,
            'body' => $delivery->body,
            'source' => $delivery->source->slug,
            'status' => $delivery->status->slug,
            'error_message' => $delivery->error_message,
            'scheduled_for' => $delivery->scheduled_for?->toIso8601String(),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}
