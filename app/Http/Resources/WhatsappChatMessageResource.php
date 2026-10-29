<?php

namespace App\Http\Resources;

use App\Models\WhatsappChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message of a one-to-one chat: who sent it, its text (null for an audio
 * or a media without caption), its kind and when.
 *
 * @mixin WhatsappChatMessage
 */
class WhatsappChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappChatMessage $message */
        $message = $this->resource;

        return [
            'friendly_id' => $message->friendly_id,
            'from_me' => $message->from_me,
            'type' => $message->message_type,
            'body' => $message->body,
            'sent_at' => $message->sent_at->toIso8601String(),
        ];
    }
}
