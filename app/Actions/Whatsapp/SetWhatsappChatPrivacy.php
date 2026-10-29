<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * What the chat screen tells WhatsApp: the read receipts and "typing…".
 * For a number, on or off, the default of its chats; for one chat, on, off
 * or null to follow its number.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SetWhatsappChatPrivacy
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    public function forAccount(User $actor, WhatsappAccount $account, bool $sendsReadReceipts, bool $sendsTyping): WhatsappAccount
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        $account->update(['sends_read_receipts' => $sendsReadReceipts, 'sends_typing' => $sendsTyping]);

        return $account;
    }

    /**
     * @throws AuthorizationException
     */
    public function forChat(User $actor, WhatsappTarget $chat, ?bool $sendsReadReceipts, ?bool $sendsTyping): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        $chat->update(['sends_read_receipts' => $sendsReadReceipts, 'sends_typing' => $sendsTyping]);

        return $chat;
    }
}
