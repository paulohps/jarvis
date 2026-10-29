<?php

namespace App\Http\Resources;

use App\Models\WhatsappMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A scheduled WhatsApp message of the user (US-7.3): the account it goes
 * from, its targets, its `{% %}` body (or the prompt the AI writes it from, or the poll it sends), its schedule and where it stands
 * (`active`, `paused`, `cancelled` or `finished`). Instants in ISO-8601 UTC.
 *
 * @mixin WhatsappMessage
 */
class WhatsappMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappMessage $message */
        $message = $this->resource;

        return [
            'friendly_id' => $message->friendly_id,
            'account' => [
                'friendly_id' => $message->account->friendly_id,
                'nickname' => $message->account->nickname,
            ],
            'targets' => WhatsappTargetResource::collection($message->targets->sortBy('id')->values()),
            'body' => $message->body,
            'ai_prompt' => $message->ai_prompt,
            'poll' => $message->poll === null ? null : [
                'question' => $message->poll['name'],
                'options' => $message->poll['options'],
                'multiple' => $message->poll['selectable_count'] !== 1,
            ],
            'state' => $message->scheduleState(),
            'recurrence' => $message->recurrence === null ? null : new RecurrenceResource($message->recurrence),
            'cancelled_at' => $message->cancelled_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
        ];
    }
}
