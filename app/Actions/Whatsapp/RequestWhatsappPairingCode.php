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
use Illuminate\Validation\ValidationException;

/**
 * Links an account that is not connected by phone number instead of the QR
 * code: WhatsApp (Linked devices → Link with phone number instead) asks for
 * the 8-character pairing code the Evolution API hands out for that number.
 *
 * An instance only hands out pairing codes when started with the number, so
 * the account moves to a new instance created with it; the old one is then
 * deleted (a failure there is only reported: the account no longer points
 * to it and its webhooks are ignored). A new instance, rather than the same
 * name again, keeps clear of the Evolution API still removing the old one.
 * Returns the QR code with the pairing code (null while the Evolution API
 * did not hand one out yet; the webhook brings it), or null once the account
 * is connected.
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class RequestWhatsappPairingCode
{
    use AuthorizesWhatsappModule;
    use ValidatesPairingNumber;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $user, WhatsappAccount $account, string $phoneNumber): ?EvolutionQrCode
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        $number = $this->pairingNumber($phoneNumber);

        if ($account->isOpen()) {
            return null;
        }

        $oldInstanceName = $account->instance_name;
        $newInstanceName = ConnectWhatsappAccount::newInstanceName();

        $qrCode = $this->client->createInstance($newInstanceName, $number);

        $account->update([
            'instance_name' => $newInstanceName,
            'whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::CONNECTING),
        ]);

        try {
            $this->client->deleteInstance($oldInstanceName);
        } catch (EvolutionApiException $exception) {
            if (! $exception->isNotFound()) {
                report($exception);
            }
        }

        return $qrCode;
    }
}
