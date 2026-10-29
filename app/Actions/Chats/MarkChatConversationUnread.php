<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Marks a conversation unread by hand, to come back to it: the list
 * shows it so until it is opened (MarkChatConversationRead).
 */
final readonly class MarkChatConversationUnread
{
    use AuthorizesChats, ChangesChatConversation;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        $this->change($this->registry, $conversation, ['marked_unread' => true]);

        return $conversation;
    }
}
