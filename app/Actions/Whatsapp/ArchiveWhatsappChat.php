<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Archives a chat (out of the list, under "Archived") or brings it back.
 * While the number is connected WhatsApp archives it too, on the phone; the
 * phone's own archiving does not reach Jarvis (the Evolution API does not
 * pass it on).
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class ArchiveWhatsappChat
{
    use AuthorizesWhatsappChat;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @param  bool  $announce  whether the owner's screens are told (WhatsappChatUpdated)
     *
     * @throws AuthorizationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, bool $archive, bool $announce = true): WhatsappTarget
    {
        $this->authorizeChat($actor, $chat);
        $account = $chat->account;
        $latest = $chat->chatMessages()->latest('sent_at')->first();

        if ($account->isOpen() && $latest !== null) {
            $this->client->archiveChat($account->instance_name, $chat->jid, $archive, [
                'key' => array_intersect_key($latest->whatsappKey(), ['remoteJid' => true, 'fromMe' => true, 'id' => true]),
                'messageTimestamp' => $latest->sent_at->getTimestamp(),
            ]);
        }

        $chat->update(['archived_at' => $archive ? ($chat->archived_at ?? now()) : null]);

        // Many chats at once (ApplyWhatsappChatsBulkAction) reload the list once instead.
        if ($announce) {
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);
        }

        return $chat;
    }
}
