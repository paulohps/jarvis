<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Deletes for everyone a message the number sent, while WhatsApp still
 * lets it (WhatsappChatMessage::canDeleteForEveryone()): it goes from the
 * contact's phone, and Jarvis keeps it marked as deleted, as it does with
 * what others delete.
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class DeleteWhatsappChatMessageForEveryone
{
    use AuthorizesWhatsappChat;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappChatMessage $message): WhatsappChatMessage
    {
        $chat = $message->target;
        $this->authorizeConnectedChat($actor, $chat, 'message');

        if (! $message->canDeleteForEveryone()) {
            throw ValidationException::withMessages(['message' => __('Only your own messages of the last :hours hours can be deleted for everyone.', ['hours' => WhatsappChatMessage::DELETE_FOR_EVERYONE_HOURS])]);
        }

        $account = $chat->account;
        $this->client->deleteForEveryone($account->instance_name, $message->whatsappKey());

        $message->update(['revoked_at' => now()]);
        WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);

        return $message;
    }
}
