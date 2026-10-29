<?php

namespace App\Actions\Chats;

use App\Actions\Reminders\CreateReminder;
use App\Models\ChatConversation;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A reminder created from a conversation of the chat screen, or from some
 * of its messages: an ordinary reminder (CreateReminder, so the
 * `reminders` module is needed too) whose note says who it is about and
 * quotes every message in the order they were sent, linked to the
 * conversation (`chat_conversation_reminders`, with its latest message)
 * so the conversation lists it.
 */
final readonly class CreateChatReminder
{
    use AuthorizesChats;

    public const int MAX_MESSAGES = 20;

    private const int QUOTE_LENGTH = 500;

    private const int NOTE_LENGTH = 5000;

    public function __construct(
        private CreateReminder $createReminder,
        private ChatProviderRegistry $registry,
    ) {}

    /**
     * @param  list<string>  $messageIds  quoted in the note, none for a reminder about the conversation
     * @param  array{title?: string|null, due_at?: string|null, workspace?: string|null}  $input  as CreateReminder reads them
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, array $messageIds, array $input): Reminder
    {
        $this->authorizeConversation($actor, $conversation);

        if (count($messageIds) > self::MAX_MESSAGES) {
            throw ValidationException::withMessages(['message' => __('Pick from 1 to :max messages.', ['max' => self::MAX_MESSAGES])]);
        }

        $driver = $this->registry->for($conversation);
        $messages = [];

        foreach (array_values(array_unique($messageIds)) as $messageId) {
            $messages[] = $driver->message($conversation, $messageId) ?? throw ValidationException::withMessages(['message' => __('Pick a message of this conversation.')]);
        }

        usort($messages, fn (ChatMessageData $a, ChatMessageData $b): int => $a->sentAt <=> $b->sentAt);

        return DB::transaction(function () use ($actor, $conversation, $messages, $input): Reminder {
            $reminder = ($this->createReminder)($actor, [
                'title' => $input['title'] ?? null,
                'due_at' => filled($input['due_at'] ?? null) ? $input['due_at'] : null,
                'note' => $this->note($conversation, $messages),
                'workspace' => $input['workspace'] ?? null,
            ]);

            $conversation->reminders()->attach($reminder->id, ['message_id' => $messages === [] ? null : $messages[array_key_last($messages)]->id]);

            return $reminder;
        });
    }

    /**
     * @param  list<ChatMessageData>  $messages
     */
    private function note(ChatConversation $conversation, array $messages): string
    {
        $lines = [__(':channel: :who', ['channel' => $conversation->provider->name, 'who' => $conversation->displayName()])];

        foreach ($messages as $message) {
            $who = $message->fromMe ? null : $message->senderName;
            $lines[] = ($who === null ? '' : "{$who}: ").'“'.Str::limit($message->text ?? $message->preview(), self::QUOTE_LENGTH).'”';
        }

        return Str::limit(implode("\n", $lines), self::NOTE_LENGTH - 3);
    }
}
