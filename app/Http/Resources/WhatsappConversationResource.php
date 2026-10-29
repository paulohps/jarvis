<?php

namespace App\Http\Resources;

use App\Models\WhatsappTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat of the WhatsApp screen of the apps, a contact or a group: its
 * name (the user's nickname first; `nickname` and `whatsapp_name` apart),
 * picture, the account it belongs to, the unread count (and whether it was
 * marked unread by hand), whether it is pinned, archived, muted (until
 * when), blocked, its timer of disappearing messages (0: off), a group's
 * description, its priority (with the user's color for it), its tags, its latest
 * message, and its settings: what reaches WhatsApp (read receipts,
 * "typing…") and whether its messages are pushed, each `null` to follow its
 * number, with what is in effect (`effective`).
 *
 * @mixin WhatsappTarget
 */
class WhatsappConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappTarget $chat */
        $chat = $this->resource;

        return [
            'friendly_id' => $chat->friendly_id,
            'name' => $chat->displayName(),
            'nickname' => $chat->nickname,
            'whatsapp_name' => $chat->name,
            'is_group' => $chat->isGroup(),
            'phone_number' => $chat->phoneNumber(),
            'picture_url' => $chat->picture_url,
            'account' => [
                'friendly_id' => $chat->account->friendly_id,
                'nickname' => $chat->account->nickname,
            ],
            'unread_count' => $chat->unread_count,
            'marked_unread' => (bool) $chat->marked_unread,
            'pinned' => $chat->isPinned(),
            'archived' => $chat->isArchived(),
            'muted_until' => $chat->isMuted() ? $chat->muted_until?->toIso8601String() : null,
            'blocked' => $chat->isBlocked(),
            'ephemeral_seconds' => (int) $chat->ephemeral_seconds,
            'description' => $chat->description,
            'priority' => $chat->priority,
            'priority_color' => $chat->priority !== null ? $chat->priorityHex($request->user()?->whatsappPriorityColors()) : null,
            'tags' => WhatsappTagResource::collection($chat->tags)->resolve($request),
            'last_message' => $chat->lastChatMessage === null ? null : new WhatsappConversationMessageResource($chat->lastChatMessage),
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'settings' => [
                'sends_read_receipts' => $chat->sends_read_receipts,
                'sends_typing' => $chat->sends_typing,
                'notifies_messages' => $chat->notifies_messages,
                'effective' => [
                    'sends_read_receipts' => $chat->sendsReadReceipts(),
                    'sends_typing' => $chat->sendsTyping(),
                    'notifies_messages' => $chat->notifiesMessages(),
                ],
            ],
        ];
    }
}
