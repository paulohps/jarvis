<?php

namespace App\Actions\Whatsapp;

use App\Actions\Reminders\CreateReminder;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A reminder created from a chat of the WhatsApp screen, or from some of
 * its messages: an ordinary reminder (CreateReminder, so the `reminders`
 * module is needed too) of the `workspace` of the input or the owner's
 * default one, whose note says who it is about and quotes every message in
 * the order they were sent, linked to the chat (`whatsapp_chat_reminders`,
 * with its latest message) so the chat lists it. The chat itself stays
 * personal, outside any workspace.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class CreateWhatsappChatReminder
{
    use AuthorizesWhatsappModule;

    private const int QUOTE_LENGTH = 500;

    public const int MAX_MESSAGES = 20;

    /** The reminders read a note of up to 5000 characters. */
    private const int NOTE_LENGTH = 5000;

    public function __construct(private CreateReminder $createReminder) {}

    /**
     * @param  list<WhatsappChatMessage>  $messages  quoted in the note, none for a reminder about the chat
     * @param  array{title?: string|null, due_at?: string|null, workspace?: string|null}  $input  as CreateReminder reads them
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, array $messages, array $input): Reminder
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        foreach ($messages as $message) {
            if ($message->whatsapp_target_id !== $chat->id) {
                throw ValidationException::withMessages(['message' => __('Pick a message of this chat.')]);
            }
        }

        if (count($messages) > self::MAX_MESSAGES) {
            throw ValidationException::withMessages(['message' => __('Pick from 1 to :max messages.', ['max' => self::MAX_MESSAGES])]);
        }

        $messages = collect($messages)->sortBy([['sent_at', 'asc'], ['id', 'asc']])->values()->all();

        return DB::transaction(function () use ($actor, $chat, $messages, $input): Reminder {
            $reminder = ($this->createReminder)($actor, [
                'title' => $input['title'] ?? null,
                'due_at' => filled($input['due_at'] ?? null) ? $input['due_at'] : null,
                'note' => $this->note($chat, $messages),
                'workspace' => $input['workspace'] ?? null,
            ]);

            $chat->reminders()->attach($reminder->id, ['whatsapp_chat_message_id' => ($messages === [] ? null : $messages[array_key_last($messages)])?->id]);

            return $reminder;
        });
    }

    /**
     * @param  list<WhatsappChatMessage>  $messages
     */
    private function note(WhatsappTarget $chat, array $messages): string
    {
        $who = $chat->phoneNumber() !== null && filled($chat->name)
            ? "{$chat->name} ({$chat->phoneNumber()})"
            : $chat->displayName();

        $lines = [__('WhatsApp: :who', ['who' => $who])];

        foreach ($messages as $message) {
            $lines[] = '“'.Str::limit($message->bodyOrPlaceholder(), self::QUOTE_LENGTH).'”';
        }

        return Str::limit(implode("\n", $lines), self::NOTE_LENGTH - 3);
    }
}
