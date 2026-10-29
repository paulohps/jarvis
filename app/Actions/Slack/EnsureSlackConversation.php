<?php

namespace App\Actions\Slack;

use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackUserDirectory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Keeps a Slack conversation and its entry on the chat screen from a
 * `users.conversations` / `conversations.info` channel object: creates
 * or updates the SlackConversation by `(account, channel id)`, and the
 * ChatConversation pointing at it, with the name and picture the screen
 * shows — the other person of a DM (fetched from Slack when not synced
 * yet, given a client), the people of a group DM, `#name` for a channel.
 *
 * The user's own state on the entry (pin, priority, nickname, tags,
 * unread count…) is never touched; a DM's unread count is taken from
 * Slack only when the entry is created.
 */
final class EnsureSlackConversation
{
    public function __construct(private readonly SlackUserDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $channel
     */
    public function __invoke(SlackAccount $account, array $channel, ?SlackApiClient $client = null): SlackConversation
    {
        $kind = SlackConversation::kindOf($channel);
        $counterpart = $kind === SlackConversation::KIND_IM ? ($channel['user'] ?? null) : null;

        $attributes = [
            'kind' => $kind,
            'name' => isset($channel['name']) ? mb_substr((string) $channel['name'], 0, 200) : null,
            'topic' => filled($channel['topic']['value'] ?? null) ? (string) $channel['topic']['value'] : null,
            'counterpart_user_id' => is_string($counterpart) ? $counterpart : null,
            'is_archived' => (bool) ($channel['is_archived'] ?? false),
            'is_member' => in_array($kind, [SlackConversation::KIND_IM, SlackConversation::KIND_MPIM], true) || (bool) ($channel['is_member'] ?? true),
        ];

        if (isset($channel['last_read'])) {
            $attributes['last_read'] = (string) $channel['last_read'];
        }

        $conversation = SlackConversation::query()->updateOrCreate(
            ['slack_account_id' => $account->id, 'channel_id' => (string) $channel['id']],
            $attributes,
        );
        $conversation->setRelation('account', $account);

        $entry = $conversation->conversation;
        $isNew = $entry === null;
        $entry ??= new ChatConversation([
            'user_id' => $account->user_id,
            'chat_provider_id' => ChatProvider::idFor(ChatProvider::SLACK),
            'unread_count' => (int) ($channel['unread_count_display'] ?? 0),
        ]);

        $entry->fill([
            'kind' => $conversation->chatKind(),
            'name' => $this->displayName($account, $conversation, $client),
            'picture_url' => $this->picture($account, $conversation, $client),
        ]);
        $entry->conversable()->associate($conversation);

        if ($isNew && $entry->kind === ChatConversation::KIND_CHANNEL && $attributes['is_archived']) {
            $entry->fill(['archived_at' => now()]);
        }

        $entry->save();
        $conversation->setRelation('conversation', $entry);

        return $conversation;
    }

    private function displayName(SlackAccount $account, SlackConversation $conversation, ?SlackApiClient $client): string
    {
        return match ($conversation->kind) {
            SlackConversation::KIND_IM => $conversation->counterpart_user_id === null
                ? __('Direct message')
                : ($this->directory->userOf($account, $conversation->counterpart_user_id, $client)?->displayName() ?? $conversation->counterpart_user_id),
            SlackConversation::KIND_MPIM => $this->groupName($account, (string) $conversation->name),
            default => '#'.$conversation->name,
        };
    }

    private function picture(SlackAccount $account, SlackConversation $conversation, ?SlackApiClient $client): ?string
    {
        if ($conversation->kind !== SlackConversation::KIND_IM || $conversation->counterpart_user_id === null) {
            return null;
        }

        return $this->directory->userOf($account, $conversation->counterpart_user_id, $client)?->picture_url;
    }

    /**
     * The people of a group DM from its name (`mpdm-ana--bia--caio-1`),
     * by their display names when they are known.
     */
    private function groupName(SlackAccount $account, string $name): string
    {
        $handles = array_values(array_filter(explode('--', (string) preg_replace('/^mpdm-(.*)-\d+$/', '$1', $name))));

        if ($handles === []) {
            return $name;
        }

        $byHandle = $account->members()->whereIn('name', $handles)->get()->keyBy('name');

        return (new Collection($handles))
            ->map(fn (string $handle): string => $byHandle->get($handle)?->displayName() ?? Str::headline($handle))
            ->join(', ');
    }
}
