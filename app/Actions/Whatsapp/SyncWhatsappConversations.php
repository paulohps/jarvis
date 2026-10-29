<?php

namespace App\Actions\Whatsapp;

use App\Jobs\SyncWhatsappChats;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * "Sync conversations": queues SyncWhatsappChats for a connected account,
 * to import the messages the Evolution API kept and ask for the full history
 * on the next linking, without linking the number again. Returns false,
 * queueing nothing, while the account is not connected.
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class SyncWhatsappConversations
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $user, WhatsappAccount $account): bool
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        if (! $account->isOpen()) {
            return false;
        }

        SyncWhatsappChats::dispatch($account);

        return true;
    }
}
