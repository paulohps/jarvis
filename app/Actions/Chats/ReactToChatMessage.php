<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds the user's reaction to a message, or takes it back (`$on`): one of
 * the emojis the provider takes (ChatCapabilities::reactionEmojis, any
 * when null). Refused when the provider has no reactions on the
 * conversation.
 */
final readonly class ReactToChatMessage
{
    use AuthorizesChats;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, string $messageId, string $emoji, bool $on = true): void
    {
        $this->authorizeConversation($actor, $conversation);

        $driver = $this->registry->for($conversation);
        $capabilities = $driver->capabilities($conversation);

        if (! $capabilities->reactions) {
            throw ValidationException::withMessages(['emoji' => __('This conversation takes no reactions.')]);
        }

        Validator::make(
            ['emoji' => $emoji],
            ['emoji' => ['required', 'string', 'max:16', $capabilities->reactionEmojis === null ? 'string' : Rule::in($capabilities->reactionEmojis)]],
            ['emoji.in' => __('This channel does not take that emoji.')],
        )->validate();

        $driver->react($conversation, $messageId, $emoji, $on);
    }
}
