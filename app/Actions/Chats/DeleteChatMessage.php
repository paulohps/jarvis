<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Deletes one of the user's own messages on the provider, when it lets
 * its messages be deleted (ChatCapabilities::deleteMessages).
 */
final readonly class DeleteChatMessage
{
    use AuthorizesChats;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, string $messageId): void
    {
        $this->authorizeConversation($actor, $conversation);

        $driver = $this->registry->for($conversation);

        if (! $driver->capabilities($conversation)->deleteMessages) {
            throw ValidationException::withMessages(['message' => __('Messages cannot be deleted on this conversation.')]);
        }

        $driver->delete($conversation, $messageId);
    }
}
