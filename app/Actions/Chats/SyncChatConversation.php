<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Sync this conversation": brings its name, picture and latest messages
 * in line with the provider, or loads the messages before `$olderThanId`
 * (the oldest shown) when the provider keeps history
 * (ChatCapabilities::history).
 */
final readonly class SyncChatConversation
{
    use AuthorizesChats;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, ?string $olderThanId = null): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        $driver = $this->registry->for($conversation);

        if ($olderThanId !== null && ! $driver->capabilities($conversation)->history) {
            throw new ChatProviderException(__('This channel keeps no older messages.'));
        }

        $driver->refresh($conversation, $olderThanId);

        return $conversation->refresh();
    }
}
