<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Moves a conversation to the archived ones of the chat screen, or brings
 * it back. Kept in Jarvis: the provider's own archive is another thing.
 */
final readonly class ArchiveChatConversation
{
    use AuthorizesChats, ChangesChatConversation;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, bool $archived): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        if ($archived !== $conversation->isArchived()) {
            $this->change($this->registry, $conversation, ['archived_at' => $archived ? now() : null]);
        }

        return $conversation;
    }
}
