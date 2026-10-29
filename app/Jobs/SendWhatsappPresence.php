<?php

namespace App\Jobs;

use App\Exceptions\EvolutionApiException;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Shows the number's presence in one chat on WhatsApp ("typing…"), or only
 * subscribes to the chat's presence with `paused`. Run after the response
 * (dispatchAfterResponse), not on the queue: the Evolution API answers only
 * once the presence is over, so the call waits at most WAIT_SECONDS and
 * lets it finish by itself; it is fire and forget, and failing is
 * reported, never retried.
 */
class SendWhatsappPresence
{
    use Dispatchable;

    public const int WAIT_SECONDS = 2;

    public function __construct(
        public WhatsappAccount $account,
        public string $jid,
        public string $presence,
        public int $milliseconds,
    ) {}

    public function handle(EvolutionApiClient $client): void
    {
        try {
            $client->sendPresence($this->account->instance_name, $this->jid, $this->presence, $this->milliseconds, self::WAIT_SECONDS);
        } catch (EvolutionApiException $exception) {
            // Timing out is expected: the presence goes on in the Evolution API.
            if ($exception->status !== null) {
                report($exception);
            }
        }
    }
}
