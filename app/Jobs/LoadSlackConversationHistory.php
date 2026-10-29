<?php

namespace App\Jobs;

use App\Actions\Slack\RecordSlackMessages;
use App\Events\ChatConversationUpdated;
use App\Models\SlackConversation;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackApiException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Loads the latest messages of a Slack conversation
 * (`conversations.history`, LIMIT at most) into `slack_messages`, on the
 * `chats` queue, and for a DM how far the user read it and how many are
 * unread (`conversations.info`). Queued by SyncSlackAccount for each
 * conversation not loaded yet; one per conversation at a time.
 *
 * `conversations.history` is Tier 1 (one call a minute) for apps
 * distributed outside the Marketplace: a rate limit releases the job for
 * as long as Slack asks, so a workspace with many conversations takes a
 * while to fill, while the events keep the screen live meanwhile.
 */
#[Tries(5)]
#[Backoff(30, 60, 120)]
#[DeleteWhenMissingModels]
#[Timeout(60)]
class LoadSlackConversationHistory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int LIMIT = 50;

    public function __construct(public SlackConversation $conversation)
    {
        $this->onQueue(ChatConversationUpdated::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->conversation->id;
    }

    public function handle(RecordSlackMessages $recordMessages): void
    {
        $conversation = $this->conversation;
        $account = $conversation->account;

        if (! $account->isUsable()) {
            return;
        }

        $client = SlackApiClient::forAccount($account);

        try {
            $history = $client->history($conversation->channel_id, null, self::LIMIT);
            $recordMessages($account, $conversation, $history['messages'], false);

            $entry = $conversation->conversation;

            if ($conversation->isDirect() && $entry !== null && $entry->unread_count === 0) {
                $info = $client->conversationInfo($conversation->channel_id);

                $conversation->forceFill(['last_read' => $info['last_read'] ?? $conversation->last_read])->save();
                $entry->forceFill(['unread_count' => (int) ($info['unread_count_display'] ?? 0)])->save();
            }

            $conversation->forceFill([
                'history_loaded_at' => now(),
                'history_exhausted_at' => $history['has_more'] ? null : now(),
            ])->save();
        } catch (SlackApiException $exception) {
            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? 60);

                return;
            }

            if ($exception->isTokenInvalid()) {
                $account->forceFill(['failed_at' => now(), 'last_error' => $exception->error])->save();

                return;
            }

            if ($exception->isNotFound()) {
                $conversation->forceFill(['is_member' => false, 'history_loaded_at' => now()])->save();

                return;
            }

            throw $exception;
        }
    }
}
