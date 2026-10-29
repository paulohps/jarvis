<?php

namespace App\Http\Resources;

use App\Models\ChatConversation;
use App\Services\Chats\ChatCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation of the chat screen, whatever its channel: the entry of
 * the list with what Jarvis adds (pin, priority, nickname, tags, archive,
 * mute, contact) and, when given, what the channel supports on it
 * (`capabilities`) and the link to it (`external_url`).
 *
 * @mixin ChatConversation
 */
class ChatConversationResource extends JsonResource
{
    private ?ChatCapabilities $capabilities = null;

    private ?string $externalUrl = null;

    /**
     * Adds what the channel supports on this conversation and its link
     * there (the `show` answer).
     */
    public function withChannel(ChatCapabilities $capabilities, ?string $externalUrl): static
    {
        $this->capabilities = $capabilities;
        $this->externalUrl = $externalUrl;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatConversation $conversation */
        $conversation = $this->resource;

        return [
            'friendly_id' => $conversation->friendly_id,
            'provider' => $conversation->provider->slug,
            'kind' => $conversation->kind,
            'name' => $conversation->name,
            'nickname' => $conversation->nickname,
            'display_name' => $conversation->displayName(),
            'picture_url' => $conversation->picture_url,
            'pinned_at' => $conversation->pinned_at?->toIso8601String(),
            'priority' => $conversation->priority,
            'priority_color' => $conversation->priorityHex(),
            'archived_at' => $conversation->archived_at?->toIso8601String(),
            'muted_until' => $conversation->isMuted() ? $conversation->muted_until?->toIso8601String() : null,
            'unread_count' => $conversation->unread_count,
            'marked_unread' => $conversation->marked_unread,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message' => $conversation->last_message,
            'tags' => ChatTagResource::collection($this->whenLoaded('tags')),
            'contact' => $this->whenLoaded('contact', fn () => $conversation->contact === null ? null : new ChatContactResource($conversation->contact)),
            'capabilities' => $this->when($this->capabilities !== null, fn () => $this->capabilities?->toArray()),
            'external_url' => $this->when($this->capabilities !== null, $this->externalUrl),
        ];
    }
}
