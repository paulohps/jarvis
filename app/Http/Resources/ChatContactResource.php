<?php

namespace App\Http\Resources;

use App\Models\ChatContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contact of the chat screen: the person behind conversations on
 * several channels, with the ids of those conversations.
 *
 * @mixin ChatContact
 */
class ChatContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatContact $contact */
        $contact = $this->resource;

        return [
            'friendly_id' => $contact->friendly_id,
            'name' => $contact->name,
            'notes' => $contact->notes,
            'conversation_ids' => $this->whenLoaded('conversations', fn () => $contact->conversations->pluck('friendly_id')->values()->all()),
        ];
    }
}
