<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * "Check connection": asks the Evolution API for the instance's state and
 * applies it as a `connection.update` webhook would (HandleEvolutionWebhook),
 * for when a webhook got lost — the same side effects follow (sync on
 * `open`, the disconnection notice on `open` → `closed`). An instance the
 * Evolution API no longer knows counts as `close`. Returns the account's
 * status slug afterwards.
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class RefreshWhatsappConnectionStatus
{
    use AuthorizesWhatsappModule;

    public function __construct(
        private EvolutionApiClient $client,
        private HandleEvolutionWebhook $handleWebhook,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $user, WhatsappAccount $account): string
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        try {
            $state = $this->client->connectionState($account->instance_name);
        } catch (EvolutionApiException $exception) {
            if (! $exception->isNotFound()) {
                throw $exception;
            }

            $state = null;
        }

        ($this->handleWebhook)([
            'event' => HandleEvolutionWebhook::CONNECTION_EVENT,
            'instance' => $account->instance_name,
            'data' => ['state' => $state ?? 'close'],
        ]);

        return $account->refresh()->status->slug;
    }
}
