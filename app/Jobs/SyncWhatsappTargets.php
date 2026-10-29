<?php

namespace App\Jobs;

use App\Events\WhatsappTargetsSynced;
use App\Exceptions\EvolutionApiException;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Support\FriendlyId;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;

/**
 * Copies the contacts and groups of a connected account into
 * `whatsapp_targets` (US-7.1, Workflow 9), on the `whatsapp` queue with 3
 * attempts. Queued when the account goes `open` and on "Sync contacts and
 * groups" (ResyncWhatsappTargets); one sync per account waits in the queue
 * at a time.
 *
 * Contacts are saved before the groups are asked for, so a groups listing
 * that times out (WhatsApp is slow on numbers with many groups; each
 * listing gets `services.evolution.sync_timeout`, the job 120s, under the
 * queue's `retry_after` of 150s) still keeps the contacts. Upserts by
 * `(whatsapp_account_id, jid)`, so syncing again never
 * duplicates: a known target gets its type, `is_synced = true` and its new
 * name and picture (a nameless entry keeps the name it had, one without a
 * picture the picture it had); targets that disappeared
 * stay, since scheduled messages may point at them. Stamps
 * `last_synced_at` at the end and tells the owner's screens
 * (`WhatsappTargetsSynced`).
 *
 * First it points the instance's webhook at the current events, so an
 * account linked before messages were reported starts reporting them; a
 * failure there is reported and does not stop the sync.
 */
#[Tries(3)]
#[Backoff(30, 120)]
#[DeleteWhenMissingModels]
#[Timeout(120)]
class SyncWhatsappTargets implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'whatsapp';

    private const int CHUNK = 500;

    public function __construct(public WhatsappAccount $account)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    public function handle(EvolutionApiClient $client): void
    {
        $instance = $this->account->instance_name;

        try {
            $client->setWebhook($instance);
        } catch (EvolutionApiException $exception) {
            report($exception);
        }

        $this->upsert($this->rows($client->fetchContacts($instance), WhatsappTargetType::CONTACT));
        $this->upsert($this->rows($client->fetchGroups($instance), WhatsappTargetType::GROUP));

        $this->account->forceFill(['last_synced_at' => now()])->save();

        WhatsappTargetsSynced::dispatch($this->account->user_id, $this->account->friendly_id);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            WhatsappTarget::query()->upsert(
                $chunk,
                ['whatsapp_account_id', 'jid'],
                [
                    'whatsapp_target_type_id',
                    'is_synced',
                    'updated_at',
                    'name' => DB::raw('coalesce(excluded.name, whatsapp_targets.name)'),
                    'picture_url' => DB::raw('coalesce(excluded.picture_url, whatsapp_targets.picture_url)'),
                ],
            );
        }
    }

    /**
     * One row per JID (the last entry wins), ready for the upsert.
     *
     * @param  list<array{jid: string, name: string|null, picture: string|null}>  $entries
     * @return list<array<string, mixed>>
     */
    private function rows(array $entries, string $type): array
    {
        $typeId = WhatsappTargetType::idFor($type);
        $now = now();
        $rows = [];

        foreach ($entries as $entry) {
            $rows[$entry['jid']] = [
                'friendly_id' => FriendlyId::generate(WhatsappTarget::idPrefix()),
                'whatsapp_account_id' => $this->account->id,
                'whatsapp_target_type_id' => $typeId,
                'jid' => $entry['jid'],
                'name' => $entry['name'],
                'picture_url' => $entry['picture'],
                'is_synced' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return array_values($rows);
    }
}
