<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The conversation of the chat screen a user addresses by friendly id,
 * with its provider and the provider's own row loaded: what the screens
 * and the API controllers resolve before calling an Action.
 */
trait FindsChatConversations
{
    /**
     * @throws ModelNotFoundException for a conversation of someone else
     */
    private function conversationOf(User $user, string $friendlyId): ChatConversation
    {
        return ChatConversation::query()
            ->ofUser($user)
            ->byFriendlyId($friendlyId)
            ->with(['provider', 'conversable', 'contact', 'tags'])
            ->firstOrFail();
    }
}
