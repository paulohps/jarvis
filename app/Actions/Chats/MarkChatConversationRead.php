<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The user opened the conversation: its unread count goes to zero and the
 * "unread" mark is cleared; the provider is told when it takes that
 * (ChatCapabilities::markRead), best effort.
 */
final readonly class MarkChatConversationRead
{
    use AuthorizesChats;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ChatConversation $conversation): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        if ($conversation->isUnread()) {
            $conversation->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();
        }

        $driver = $this->registry->for($conversation);

        if ($driver->capabilities($conversation)->markRead) {
            $driver->markRead($conversation);
        }

        return $conversation;
    }
}
