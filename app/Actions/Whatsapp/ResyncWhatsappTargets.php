<?php

namespace App\Actions\Whatsapp;

use App\Jobs\SyncWhatsappTargets;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * "Sync contacts and groups": queues SyncWhatsappTargets for a connected
 * account, to bring contacts and groups the number got since it connected.
 * Returns false, queueing nothing, while the account is not connected (the
 * Evolution API has nothing to hand out then).
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class ResyncWhatsappTargets
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

        SyncWhatsappTargets::dispatch($account);

        return true;
    }
}
