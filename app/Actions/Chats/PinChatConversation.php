<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Pins a conversation to the top of the chat screen, or unpins it: any
 * number may be pinned, the latest pinned first. Pinning again keeps the
 * original order.
 */
final readonly class PinChatConversation
{
    use AuthorizesChats, ChangesChatConversation;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, bool $pinned): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        if ($pinned !== $conversation->isPinned()) {
            $this->change($this->registry, $conversation, ['pinned_at' => $pinned ? now() : null]);
        }

        return $conversation;
    }
}
