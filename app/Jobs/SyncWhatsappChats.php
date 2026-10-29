<?php

namespace App\Jobs;

use App\Actions\Whatsapp\RecordWhatsappChatMessages;
use App\Exceptions\EvolutionApiException;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * "Sync conversations" of a connected account, without linking the number
 * again, on the `whatsapp` queue with 3 attempts:
 *
 * 1. points the instance's webhook at the message events, so what comes
 *    from now on is kept;
 * 2. turns `syncFullHistory` on, so the next linking (and only a linking:
 *    WhatsApp hands the old history over then) brings the full history;
 * 3. imports, page by page (PER_PAGE, newest first), the messages the
 *    Evolution API kept itself (`DATABASE_SAVE_DATA_NEW_MESSAGE` and
 *    `_HISTORIC` on), through RecordWhatsappChatMessages: groups are left
 *    out and a message already kept is left as is, so syncing again only
 *    adds what is missing.
 *
 * The first two failing are reported and do not stop the import. Stamps
 * `chats_synced_at` at the end.
 */
#[Tries(3)]
#[Backoff(30, 120)]
#[DeleteWhenMissingModels]
#[Timeout(600)]
class SyncWhatsappChats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int PER_PAGE = 500;

    public function __construct(public WhatsappAccount $account)
    {
        $this->onQueue(SyncWhatsappTargets::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    public function handle(EvolutionApiClient $client, RecordWhatsappChatMessages $recordMessages): void
    {
        $instance = $this->account->instance_name;

        foreach ([$client->setWebhook(...), $client->enableFullHistory(...)] as $step) {
            try {
                $step($instance);
            } catch (EvolutionApiException $exception) {
                report($exception);
            }
        }

        $page = 1;

        do {
            $result = $client->findMessages($instance, $page, self::PER_PAGE);
            $recordMessages($this->account, $result['records']);
            $page++;
        } while ($page <= $result['pages'] && $result['records'] !== []);

        $this->account->forceFill(['chats_synced_at' => now()])->save();
    }
}
