<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edits the text (or caption) of a message the number sent, within
 * WhatsApp's 15 minutes (WhatsappChatMessage::canEdit()): the contact sees
 * the new text marked "Edited", and so does Jarvis.
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class EditWhatsappChatMessage
{
    use AuthorizesWhatsappChat;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappChatMessage $message, string $text): WhatsappChatMessage
    {
        $chat = $message->target;
        $this->authorizeConnectedChat($actor, $chat, 'text');

        /** @var array{text: string} $validated */
        $validated = Validator::make(['text' => mb_trim($text)], ['text' => ['required', 'string', 'max:'.SendWhatsappChatMessage::MAX_LENGTH]])->validate();

        if (! $message->canEdit()) {
            throw ValidationException::withMessages(['text' => __('Only your own texts of the last :minutes minutes can be edited.', ['minutes' => WhatsappChatMessage::EDIT_MINUTES])]);
        }

        if ($validated['text'] === $message->body) {
            return $message;
        }

        $account = $chat->account;
        $this->client->editMessage($account->instance_name, $message->whatsappKey(), $validated['text']);

        $message->update(['body' => $validated['text'], 'edited_at' => now()]);
        WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);

        return $message;
    }
}
