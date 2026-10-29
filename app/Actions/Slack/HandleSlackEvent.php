<?php

namespace App\Actions\Slack;

use App\Events\ChatAccountSynced;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackApiException;
use App\Services\Slack\SlackUserDirectory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Applies one Events API callback (`event_callback`) to every account of
 * its workspace (`team_id`): Slack delivers an event once per app, whoever
 * among the connected users can see it.
 *
 * - `message`: a new message is recorded in the conversation of each
 *   account that has it; an account without it asks Slack
 *   (`conversations.info`) and keeps the conversation when the user is in
 *   it (a DM is theirs by definition). `message_changed` and
 *   `message_deleted` update the message; the other subtypes (someone
 *   joined, the topic changed…) are kept as system notices.
 * - `reaction_added` / `reaction_removed`: the message's reactions.
 * - `channel_rename`, `channel_archive`, `channel_unarchive`, `channel_deleted`,
 *   `group_*`, `im_created`, `member_joined_channel`, `member_left_channel`,
 *   `channel_left`, `group_left`: the conversations.
 * - `user_change`: the person.
 * - `tokens_revoked`, `app_uninstalled`: the accounts stop working until
 *   connected again.
 *
 * Returns whether anything was handled.
 */
final class HandleSlackEvent
{
    public function __construct(
        private readonly EnsureSlackConversation $ensureConversation,
        private readonly RecordSlackMessages $recordMessages,
        private readonly SlackUserDirectory $directory,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the whole callback
     */
    public function __invoke(array $payload): bool
    {
        $teamId = $payload['team_id'] ?? null;
        $event = $payload['event'] ?? null;

        if (! is_string($teamId) || ! is_array($event)) {
            return false;
        }

        $accounts = SlackAccount::query()->where('team_id', $teamId)->with('user')->get();

        if ($accounts->isEmpty()) {
            return false;
        }

        $type = (string) ($event['type'] ?? '');

        return match ($type) {
            'message' => $this->message($accounts, $event),
            'reaction_added', 'reaction_removed' => $this->reaction($accounts, $event, $type === 'reaction_added'),
            'channel_rename', 'group_rename' => $this->renamed($accounts, (array) ($event['channel'] ?? [])),
            'channel_archive', 'group_archive' => $this->archived($accounts, (string) ($event['channel'] ?? ''), true),
            'channel_unarchive', 'group_unarchive' => $this->archived($accounts, (string) ($event['channel'] ?? ''), false),
            'channel_deleted', 'group_deleted' => $this->left($accounts, (string) ($event['channel'] ?? '')),
            'channel_left', 'group_left' => $this->left($accounts, (string) ($event['channel'] ?? ''), (string) ($event['user'] ?? '')),
            'member_joined_channel' => $this->joined($accounts, (string) ($event['channel'] ?? ''), (string) ($event['user'] ?? '')),
            'member_left_channel' => $this->left($accounts, (string) ($event['channel'] ?? ''), (string) ($event['user'] ?? '')),
            'im_created' => $this->ensureForAll($accounts, (string) (($event['channel']['id'] ?? $event['channel']) ?? '')),
            'user_change' => $this->userChanged($accounts, (array) ($event['user'] ?? [])),
            'tokens_revoked' => $this->tokensRevoked($accounts, array_values(array_filter((array) ($event['tokens']['oauth'] ?? []), 'is_string'))),
            'app_uninstalled' => $this->failAll($accounts, 'app_uninstalled'),
            default => false,
        };
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     * @param  array<string, mixed>  $event
     */
    private function message(Collection $accounts, array $event): bool
    {
        $channelId = (string) ($event['channel'] ?? '');
        $subtype = (string) ($event['subtype'] ?? '');
        $handled = false;

        foreach ($accounts as $account) {
            $conversation = $this->conversationOf($account, $channelId, create: $subtype === '' || in_array($subtype, ['file_share', 'thread_broadcast', 'me_message'], true));

            if ($conversation === null) {
                continue;
            }

            match ($subtype) {
                'message_changed' => $this->recordMessages->changed($account, $conversation, (array) ($event['message'] ?? [])),
                'message_deleted' => $this->recordMessages->deleted($account, $conversation, (string) ($event['deleted_ts'] ?? ($event['previous_message']['ts'] ?? ''))),
                'message_replied' => $this->recordMessages->changed($account, $conversation, (array) ($event['message'] ?? [])),
                default => $this->recordMessages->__invoke($account, $conversation, [$event], true),
            };

            $handled = true;
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     * @param  array<string, mixed>  $event
     */
    private function reaction(Collection $accounts, array $event, bool $on): bool
    {
        $item = (array) ($event['item'] ?? []);

        if (($item['type'] ?? null) !== 'message') {
            return false;
        }

        $handled = false;

        foreach ($accounts as $account) {
            $conversation = $this->conversationOf($account, (string) ($item['channel'] ?? ''));

            if ($conversation === null) {
                continue;
            }

            $this->recordMessages->reacted($account, $conversation, (string) ($item['ts'] ?? ''), (string) ($event['reaction'] ?? ''), (string) ($event['user'] ?? ''), $on);
            $handled = true;
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     * @param  array<string, mixed>  $channel  `{id, name, …}`
     */
    private function renamed(Collection $accounts, array $channel): bool
    {
        $handled = false;

        foreach ($accounts as $account) {
            $conversation = $this->conversationOf($account, (string) ($channel['id'] ?? ''));

            if ($conversation === null) {
                continue;
            }

            ($this->ensureConversation)($account, [...$channel, 'is_private' => $conversation->kind === SlackConversation::KIND_PRIVATE, 'is_archived' => $conversation->is_archived]);
            $handled = true;
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     */
    private function archived(Collection $accounts, string $channelId, bool $archived): bool
    {
        $handled = false;

        foreach ($accounts as $account) {
            $conversation = $this->conversationOf($account, $channelId);

            if ($conversation === null) {
                continue;
            }

            $conversation->forceFill(['is_archived' => $archived])->save();
            $conversation->conversation?->forceFill(['archived_at' => $archived ? now() : null])->save();
            $handled = true;
        }

        return $handled;
    }

    /**
     * The account's user left, was removed, or the conversation is gone:
     * it stays listed but read only. Given `$userId`, only that user's
     * account is concerned.
     *
     * @param  Collection<int, SlackAccount>  $accounts
     */
    private function left(Collection $accounts, string $channelId, string $userId = ''): bool
    {
        $handled = false;

        foreach ($accounts as $account) {
            if ($userId !== '' && $account->slack_user_id !== $userId) {
                continue;
            }

            $conversation = $this->conversationOf($account, $channelId);

            if ($conversation === null) {
                continue;
            }

            $conversation->forceFill(['is_member' => false])->save();
            $handled = true;
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     */
    private function joined(Collection $accounts, string $channelId, string $userId): bool
    {
        $handled = false;

        foreach ($accounts as $account) {
            if ($account->slack_user_id !== $userId) {
                continue;
            }

            $handled = $this->conversationOf($account, $channelId, create: true) !== null || $handled;
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     */
    private function ensureForAll(Collection $accounts, string $channelId): bool
    {
        $handled = false;

        foreach ($accounts as $account) {
            $handled = $this->conversationOf($account, $channelId, create: true) !== null || $handled;
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     * @param  array<string, mixed>  $member
     */
    private function userChanged(Collection $accounts, array $member): bool
    {
        foreach ($accounts as $account) {
            $this->directory->remember($account, $member);
        }

        return true;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     * @param  list<string>  $userIds
     */
    private function tokensRevoked(Collection $accounts, array $userIds): bool
    {
        $handled = false;

        foreach ($accounts as $account) {
            if (in_array($account->slack_user_id, $userIds, true)) {
                $this->fail($account, 'token_revoked');
                $handled = true;
            }
        }

        return $handled;
    }

    /**
     * @param  Collection<int, SlackAccount>  $accounts
     */
    private function failAll(Collection $accounts, string $error): bool
    {
        foreach ($accounts as $account) {
            $this->fail($account, $error);
        }

        return $accounts->isNotEmpty();
    }

    private function fail(SlackAccount $account, string $error): void
    {
        $account->forceFill(['failed_at' => now(), 'last_error' => $error])->save();

        ChatAccountSynced::dispatch($account->user_id, ChatProvider::SLACK, $account->friendly_id);
    }

    /**
     * The account's conversation with the channel id; when `$create`, one
     * not known yet is asked from Slack and kept if the user is in it.
     */
    private function conversationOf(SlackAccount $account, string $channelId, bool $create = false): ?SlackConversation
    {
        if ($channelId === '' || ! $account->isUsable()) {
            return null;
        }

        $conversation = $account->conversations()->where('channel_id', $channelId)->with('conversation')->first();

        if ($conversation !== null) {
            $conversation->setRelation('account', $account);

            return $conversation;
        }

        if (! $create) {
            return null;
        }

        $client = SlackApiClient::forAccount($account);

        try {
            $channel = $client->conversationInfo($channelId);
        } catch (SlackApiException $exception) {
            if ($exception->isTokenInvalid()) {
                $this->fail($account, (string) $exception->error);
            } elseif (! $exception->isNotFound()) {
                report($exception);
            }

            return null;
        }

        $kind = SlackConversation::kindOf($channel);

        if (in_array($kind, [SlackConversation::KIND_PUBLIC, SlackConversation::KIND_PRIVATE], true) && ! ($channel['is_member'] ?? false)) {
            return null;
        }

        return ($this->ensureConversation)($account, $channel, $client);
    }
}
