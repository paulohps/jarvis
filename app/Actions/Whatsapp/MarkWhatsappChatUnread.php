<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Marks a chat as unread by hand (the dot in the list, as on WhatsApp) or
 * takes the mark off. While the number is connected, marking it unread
 * does it on the phone too; opening the chat takes the mark off.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class MarkWhatsappChatUnread
{
    use AuthorizesWhatsappChat;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @param  bool  $announce  whether the owner's screens are told (WhatsappChatUpdated)
     *
     * @throws AuthorizationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, bool $unread, bool $announce = true): WhatsappTarget
    {
        $this->authorizeChat($actor, $chat);
        $account = $chat->account;
        $latest = $unread ? $chat->chatMessages()->latest('sent_at')->first() : null;

        if ($account->isOpen() && $latest !== null) {
            $this->client->markChatUnread($account->instance_name, $chat->jid, [
                'key' => array_intersect_key($latest->whatsappKey(), ['remoteJid' => true, 'fromMe' => true, 'id' => true]),
                'messageTimestamp' => $latest->sent_at->getTimestamp(),
            ]);
        }

        $chat->update(['marked_unread' => $unread]);

        // Many chats at once (ApplyWhatsappChatsBulkAction) reload the list once instead.
        if ($announce) {
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);
        }

        return $chat;
    }
}
