<?php

namespace App\Actions\Chats;

use App\Actions\Whatsapp\SendWhatsappChatContent;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use App\Services\Chats\OutgoingChatMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Sends a poll to a conversation as the user, through its provider's
 * driver, where the provider has polls (ChatCapabilities::$polls): its
 * question, 2 to 10 options and one answer or as many as each person
 * wants (`$multiple`), with the rules of SendWhatsappChatContent::pollOf()
 * (errors under `question`, `options` and `options.*`; `poll` where the conversation has none).
 *
 * Only the owner of the conversation, with the provider switched on.
 */
final readonly class SendChatPoll
{
    use AuthorizesChats;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @param  array<mixed>  $options
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ChatProviderException
     */
    public function __invoke(User $actor, ChatConversation $conversation, mixed $question, array $options, bool $multiple): ChatMessageData
    {
        $this->authorizeConversation($actor, $conversation);

        $driver = $this->registry->for($conversation);
        $capabilities = $driver->capabilities($conversation);

        if (! $capabilities->send || ! $capabilities->polls) {
            throw ValidationException::withMessages(['poll' => __('Polls cannot be sent to this conversation.')]);
        }

        return $driver->send($conversation, new OutgoingChatMessage(poll: SendWhatsappChatContent::pollOf($question, $options, $multiple)));
    }
}
