<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Blocks a contact on WhatsApp (they can no longer message or call the
 * number), or unblocks them. A group cannot be blocked. What the phone
 * blocks does not reach Jarvis (the Evolution API does not pass it on).
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class BlockWhatsappContact
{
    use AuthorizesWhatsappChat;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, bool $block): WhatsappTarget
    {
        $this->authorizeConnectedChat($actor, $chat, 'block');

        if ($chat->isGroup()) {
            throw ValidationException::withMessages(['block' => __('A group cannot be blocked.')]);
        }

        $account = $chat->account;
        $this->client->updateBlockStatus($account->instance_name, $chat->jid, $block);

        $chat->update(['blocked_at' => $block ? ($chat->blocked_at ?? now()) : null]);
        WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);

        return $chat;
    }
}
