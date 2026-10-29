<?php

namespace App\Http\Resources;

use App\Models\ChatScheduledMessage;
use App\Models\RecurrenceFrequency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message scheduled to a conversation of the chat screen.
 *
 * @mixin ChatScheduledMessage
 */
class ChatScheduledMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatScheduledMessage $scheduled */
        $scheduled = $this->resource;

        return [
            'friendly_id' => $scheduled->friendly_id,
            'conversation_id' => $this->whenLoaded('conversation', fn () => $scheduled->conversation->friendly_id),
            'body' => $scheduled->body,
            'ai_prompt' => $scheduled->ai_prompt,
            'poll' => $scheduled->poll === null ? null : [
                'question' => $scheduled->poll['name'],
                'options' => $scheduled->poll['options'],
                'multiple' => $scheduled->poll['selectable_count'] !== 1,
            ],
            'thread_id' => $scheduled->thread_id,
            'frequency' => $scheduled->recurrence?->frequency->slug ?? RecurrenceFrequency::ONCE,
            'send_at' => $scheduled->nextSendAt()?->toIso8601String(),
            'sent_at' => $scheduled->sent_at?->toIso8601String(),
            'cancelled_at' => $scheduled->cancelled_at?->toIso8601String(),
            'failed_at' => $scheduled->failed_at?->toIso8601String(),
            'error' => $scheduled->error,
        ];
    }
}
