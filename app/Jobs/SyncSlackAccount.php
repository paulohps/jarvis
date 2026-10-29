<?php

namespace App\Jobs;

use App\Actions\Slack\EnsureSlackConversation;
use App\Events\ChatAccountSynced;
use App\Events\ChatConversationUpdated;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackApiException;
use App\Services\Slack\SlackUserDirectory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Brings a Slack account in line with the workspace, on the `chats`
 * queue: who the token is (`auth.test`), everyone in the workspace
 * (`users.list`), and the conversations the user is in
 * (`users.conversations`), each kept with its entry on the chat screen
 * (EnsureSlackConversation); the ones the user is no longer in go read
 * only. Then the latest messages of each conversation are loaded in the
 * background (LoadSlackConversationHistory), DMs first.
 *
 * Queued on connecting and on "Sync conversations"; one per account at a
 * time. A token Slack no longer accepts marks the account failed; a rate
 * limit waits as long as Slack asks.
 */
#[Tries(3)]
#[Backoff(30, 120)]
#[DeleteWhenMissingModels]
#[Timeout(110)]
class SyncSlackAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public SlackAccount $account)
    {
        $this->onQueue(ChatConversationUpdated::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    public function handle(EnsureSlackConversation $ensureConversation, SlackUserDirectory $directory): void
    {
        $account = $this->account;
        $client = SlackApiClient::forAccount($account);

        try {
            $identity = $client->authTest();

            $account->forceFill([
                'team_name' => mb_substr((string) ($identity['team'] ?? $account->team_name), 0, 100),
                'team_domain' => $this->domainOf($identity['url'] ?? null) ?? $account->team_domain,
                'slack_user_id' => (string) ($identity['user_id'] ?? $account->slack_user_id),
            ])->save();

            foreach ($client->users() as $member) {
                $directory->remember($account, (array) $member);
            }

            $seen = [];

            foreach ($client->userConversations() as $channel) {
                $seen[] = $ensureConversation($account, (array) $channel, $client)->id;
            }

            $account->conversations()->whereNotIn('id', $seen)->update(['is_member' => false]);
            $account->forceFill(['synced_at' => now(), 'failed_at' => null, 'last_error' => null])->save();
        } catch (SlackApiException $exception) {
            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? 60);

                return;
            }

            if ($exception->isTokenInvalid()) {
                $account->forceFill(['failed_at' => now(), 'last_error' => $exception->error])->save();
                ChatAccountSynced::dispatch($account->user_id, ChatProvider::SLACK, $account->friendly_id);

                return;
            }

            throw $exception;
        }

        $account->conversations()
            ->where('is_member', true)
            ->whereNull('history_loaded_at')
            ->orderByRaw("case kind when 'im' then 0 when 'mpim' then 1 else 2 end")
            ->orderBy('id')
            ->each(function (SlackConversation $conversation): void {
                LoadSlackConversationHistory::dispatch($conversation);
            });

        ChatAccountSynced::dispatch($account->user_id, ChatProvider::SLACK, $account->friendly_id);
    }

    private function domainOf(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_ends_with($host, '.slack.com') ? mb_substr(substr($host, 0, -10), 0, 100) : null;
    }
}
