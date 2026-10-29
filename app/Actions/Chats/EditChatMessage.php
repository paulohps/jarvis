<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes the text of one of the user's own messages on the provider,
 * when it lets its messages be edited (ChatCapabilities::editMessages).
 */
final readonly class EditChatMessage
{
    use AuthorizesChats;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, string $messageId, ?string $text): ChatMessageData
    {
        $this->authorizeConversation($actor, $conversation);

        $driver = $this->registry->for($conversation);

        if (! $driver->capabilities($conversation)->editMessages) {
            throw ValidationException::withMessages(['text' => __('Messages cannot be edited on this conversation.')]);
        }

        /** @var array{text: string} $validated */
        $validated = Validator::make(
            ['text' => filled($text) ? mb_trim((string) $text) : null],
            ['text' => ['required', 'string', 'max:'.SendChatMessage::MAX_LENGTH]],
            attributes: ['text' => __('message')],
        )->validate();

        return $driver->edit($conversation, $messageId, $validated['text']);
    }
}
