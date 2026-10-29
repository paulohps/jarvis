<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * "Restart connection": drops and reopens the connection of a connected
 * account that got stuck (messages not leaving), keeping the number linked.
 * The Evolution API reconnects on its own and reports no `close` for it, so
 * no disconnection is notified; the webhook brings the status back. Returns
 * false, doing nothing, while the account is not connected — that one
 * reconnects by QR code or pairing code instead.
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class RestartWhatsappConnection
{
    use AuthorizesWhatsappModule;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $user, WhatsappAccount $account): bool
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        if (! $account->isOpen()) {
            return false;
        }

        $this->client->restart($account->instance_name);

        return true;
    }
}
