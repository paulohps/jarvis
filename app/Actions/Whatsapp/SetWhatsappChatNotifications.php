<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Whether the messages a chat gets reach the apps by push
 * (WhatsappMessageReceived). For a number, on or off, the default of its
 * chats; for one chat, on, off or null to follow its number.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SetWhatsappChatNotifications
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    public function forAccount(User $actor, WhatsappAccount $account, bool $notifiesMessages): WhatsappAccount
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        $account->update(['notifies_messages' => $notifiesMessages]);

        return $account;
    }

    /**
     * Whether a chat of the number read on the phone is read in Jarvis too.
     *
     * @throws AuthorizationException
     */
    public function syncPhoneReads(User $actor, WhatsappAccount $account, bool $syncsPhoneReads): WhatsappAccount
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        $account->update(['syncs_phone_reads' => $syncsPhoneReads]);

        return $account;
    }

    /**
     * @throws AuthorizationException
     */
    public function forChat(User $actor, WhatsappTarget $chat, ?bool $notifiesMessages): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        $chat->update(['notifies_messages' => $notifiesMessages]);

        return $chat;
    }
}
