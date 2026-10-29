<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\Recurrence;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Removes one of the user's WhatsApp numbers (US-7.2):
 *
 * 1. pauses the recurrences of every message scheduled from it, first, so
 *    the scheduler stops sending through it right away;
 * 2. logs the number out and deletes the instance on the Evolution API — an
 *    instance already logged out (400) or gone (404) is fine, any other
 *    failure stops the removal with the account kept;
 * 3. deletes the account. The database removes its targets, messages and
 *    deliveries with it (`cascadeOnDelete`) and clears it as the source of
 *    the user's WhatsApp notifications (`nullOnDelete`).
 *
 * Only the owner may remove it, with the `whatsapp` module.
 */
final readonly class RemoveWhatsappAccount
{
    use AuthorizesWhatsappModule;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $user, WhatsappAccount $account): void
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('delete', $account);

        $this->pauseScheduledMessages($account);

        $this->ignoringGoneInstance(fn () => $this->client->logout($account->instance_name));
        $this->ignoringGoneInstance(fn () => $this->client->deleteInstance($account->instance_name));

        $account->delete();
    }

    private function pauseScheduledMessages(WhatsappAccount $account): void
    {
        Recurrence::query()
            ->where('schedulable_type', (new WhatsappMessage)->getMorphClass())
            ->whereIn('schedulable_id', WhatsappMessage::withTrashed()->where('whatsapp_account_id', $account->id)->select('id'))
            ->update(['is_paused' => true, 'updated_at' => now()]);
    }

    /**
     * @param  callable(): void  $call
     *
     * @throws EvolutionApiException
     */
    private function ignoringGoneInstance(callable $call): void
    {
        try {
            $call();
        } catch (EvolutionApiException $exception) {
            if (! in_array($exception->status, [400, 404], true)) {
                throw $exception;
            }
        }
    }
}
