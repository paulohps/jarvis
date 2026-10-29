<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Services\Whatsapp\EvolutionQrCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * A fresh QR code for an account that is not connected (US-7.1, US-7.2):
 * renews an expired one while the connection screen stays open, and
 * reconnects a `closed` account, which goes back to `connecting`. When the
 * instance is gone from the Evolution API it is created again under the
 * same name. Given the number the account is being linked by, a pairing
 * code comes along. Returns null once the account is connected.
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class RenewWhatsappQrCode
{
    use AuthorizesWhatsappModule;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $user, WhatsappAccount $account, ?string $pairingNumber = null): ?EvolutionQrCode
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        if ($account->isOpen()) {
            return null;
        }

        try {
            $qrCode = $this->client->connect($account->instance_name, $pairingNumber);
        } catch (EvolutionApiException $exception) {
            if (! $exception->isNotFound()) {
                throw $exception;
            }

            $qrCode = $this->client->createInstance($account->instance_name, $pairingNumber);

            if ($account->hasProxy()) {
                try {
                    $this->client->setProxy($account->instance_name, $account->proxy());
                } catch (EvolutionApiException $proxyException) {
                    report($proxyException);
                }
            }
        }

        if ($account->status->slug === WhatsappConnectionStatus::CLOSED) {
            $account->update(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::CONNECTING)]);
        }

        return $qrCode;
    }
}
