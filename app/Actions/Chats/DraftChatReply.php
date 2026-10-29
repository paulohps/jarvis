<?php

namespace App\Actions\Chats;

use App\Ai\Agents\ChatReplyAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Drafts one answer to one or more messages of a conversation (up to
 * MAX_MESSAGES, at least one someone else sent), in the owner's style
 * (ChatReplyAgent). Nothing is sent: the draft goes into the composer.
 * `instruction`, up to MAX_INSTRUCTION characters, says what the answer
 * must say. Without messages, the latest ones received since the owner's
 * last message are answered. `fromPrompt()` writes a new message from the
 * instruction alone.
 *
 * What the AI provider throws is left to the caller.
 */
final readonly class DraftChatReply
{
    use AuthorizesChats;

    public const int MAX_INSTRUCTION = 2000;

    public const int MAX_MESSAGES = 20;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @param  list<string>  $messageIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws AiProviderNotConfigured
     */
    public function __invoke(User $actor, ChatConversation $conversation, array $messageIds, ?string $instruction = null): string
    {
        $this->authorizeConversation($actor, $conversation);

        /** @var array{instruction: string|null} $validated */
        $validated = Validator::make(
            ['instruction' => filled($instruction) ? mb_trim((string) $instruction) : null],
            ['instruction' => ['nullable', 'string', 'max:'.self::MAX_INSTRUCTION]],
        )->validate();

        $driver = $this->registry->for($conversation);
        $messageIds = array_values(array_unique(array_filter($messageIds, 'is_string')));

        if (count($messageIds) > self::MAX_MESSAGES) {
            throw ValidationException::withMessages(['message' => __('Pick from 1 to :max messages.', ['max' => self::MAX_MESSAGES])]);
        }

        $messages = [];

        foreach ($messageIds as $messageId) {
            $messages[] = $driver->message($conversation, $messageId) ?? throw ValidationException::withMessages(['message' => __('Pick a message of this conversation.')]);
        }

        if ($messages === []) {
            $messages = $this->latestReceived(array_values($driver->messages($conversation, null, ChatReplyAgent::CONTEXT_MESSAGES)->all()));
        }

        if ($messages === [] || array_all($messages, fn (ChatMessageData $message): bool => $message->fromMe)) {
            throw ValidationException::withMessages(['message' => __('Pick a message someone else sent.')]);
        }

        return ChatReplyAgent::forUser($actor, $driver)->draft($conversation, $messages, $validated['instruction']);
    }

    /**
     * A new message to the conversation from the `instruction` alone.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws AiProviderNotConfigured
     */
    public function fromPrompt(User $actor, ChatConversation $conversation, ?string $instruction): string
    {
        $this->authorizeConversation($actor, $conversation);

        /** @var array{instruction: string} $validated */
        $validated = Validator::make(
            ['instruction' => filled($instruction) ? mb_trim((string) $instruction) : null],
            ['instruction' => ['required', 'string', 'max:'.self::MAX_INSTRUCTION]],
            ['instruction.required' => __('Say what the message must say.')],
        )->validate();

        return ChatReplyAgent::forUser($actor, $this->registry->for($conversation))->compose($conversation, $validated['instruction']);
    }

    /**
     * The messages others sent after the owner's last one, oldest first.
     *
     * @param  list<ChatMessageData>  $messages  oldest first
     * @return list<ChatMessageData>
     */
    private function latestReceived(array $messages): array
    {
        $received = [];

        foreach (array_reverse($messages) as $message) {
            if ($message->fromMe) {
                break;
            }

            if ($message->kind !== 'system' && ! $message->isDeleted()) {
                array_unshift($received, $message);
            }
        }

        return array_slice($received, -self::MAX_MESSAGES);
    }
}
