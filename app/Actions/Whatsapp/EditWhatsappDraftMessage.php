<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappDraftMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes by hand a message the AI wrote in a conversation with the owner
 * (WhatsappDraftConversation): the next requests are about the message as
 * the owner left it, and the AI is told it was edited.
 *
 * Only the owner of the conversation, with the `whatsapp` module; only the
 * AI's messages, up to the length WhatsApp takes.
 */
final readonly class EditWhatsappDraftMessage
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappDraftMessage $message, ?string $body): WhatsappDraftMessage
    {
        $this->authorizeWhatsapp($actor);

        if ($message->conversation->user_id !== $actor->id || ! $message->isFromAssistant()) {
            throw new AuthorizationException;
        }

        /** @var array{body: string} $validated */
        $validated = Validator::make(
            ['body' => filled($body) ? mb_trim((string) $body) : null],
            ['body' => ['required', 'string', 'max:'.SendWhatsappChatMessage::MAX_LENGTH]],
        )->validate();

        if ($validated['body'] !== $message->body) {
            $message->update(['body' => $validated['body'], 'edited_at' => now()]);
            $message->conversation->touch();
        }

        return $message;
    }
}
