<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;

/**
 * Writes the user's own state of an entry (pin, archive, mute, unread
 * mark, nickname, priority) through its provider first: one that keeps
 * that state on its own row (WhatsApp, on the phone too) applies it there
 * and may refuse; then the entry takes it.
 */
trait ChangesChatConversation
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ChatProviderException
     */
    private function change(ChatProviderRegistry $registry, ChatConversation $conversation, array $attributes): ChatConversation
    {
        $registry->for($conversation)->entryChanged($conversation, $attributes);

        $conversation->forceFill($attributes)->save();

        return $conversation;
    }
}
