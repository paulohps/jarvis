<?php

namespace App\Services\Slack;

use App\Actions\Slack\EnsureSlackConversation;
use App\Actions\Slack\RecordSlackMessages;
use App\Events\ChatConversationUpdated;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\SlackUser;
use App\Models\User;
use App\Services\Chats\ChatAccountData;
use App\Services\Chats\ChatCapabilities;
use App\Services\Chats\ChatFileContents;
use App\Services\Chats\ChatFileData;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatParticipantData;
use App\Services\Chats\ChatProviderDriver;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatReactionData;
use App\Services\Chats\OutgoingChatMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Slack on the chat layer (Key Concept "Chat channel"): the conversations
 * of the workspaces the user connected (SlackAccount), read from
 * `slack_messages` — filled by the Events API and the history jobs — and
 * written through the Web API as the user. A conversation whose account
 * stopped working, that was archived or that the user left is read only.
 *
 * Message ids are the friendly ids of the SlackMessages (`slm_…`); file
 * ids Slack's own (`F…`). Reactions are the characters of SlackEmoji, so
 * the picker offers only what Slack has a name for.
 */
final class SlackChatProvider implements ChatProviderDriver
{
    /**
     * The user scopes asked on connecting: reading and writing the
     * conversations the user is in, their files and reactions, and the
     * people's names.
     */
    public const array USER_SCOPES = [
        'channels:history', 'channels:read',
        'groups:history', 'groups:read',
        'im:history', 'im:read', 'im:write',
        'mpim:history', 'mpim:read', 'mpim:write',
        'chat:write', 'reactions:read', 'reactions:write',
        'users:read', 'files:read', 'files:write',
    ];

    /**
     * A file above this is not fetched from Slack (the link to Slack is
     * offered instead).
     */
    public const int MAX_FILE_BYTES = 25 * 1024 * 1024;

    /**
     * How long a signed file URL handed to the screens works.
     */
    private const int FILE_URL_MINUTES = 60 * 24;

    private const int PARTICIPANTS_CACHE_SECONDS = 600;

    public function __construct(
        private readonly SlackUserDirectory $directory,
        private readonly RecordSlackMessages $recordMessages,
        private readonly EnsureSlackConversation $ensureConversation,
    ) {}

    public function slug(): string
    {
        return ChatProvider::SLACK;
    }

    public function capabilities(?ChatConversation $conversation = null): ChatCapabilities
    {
        $capabilities = new ChatCapabilities(
            send: true,
            files: true,
            threads: true,
            reactions: true,
            reactionEmojis: SlackEmoji::characters(),
            editMessages: true,
            deleteMessages: true,
            mentions: true,
            markRead: true,
            history: true,
            externalLink: true,
            formatting: 'mrkdwn',
        );

        if ($conversation === null) {
            return $capabilities;
        }

        $slack = $this->slackOf($conversation);

        return $this->isWritable($slack) ? $capabilities : $capabilities->readOnly();
    }

    public function accountsOf(User $user): Collection
    {
        return SlackAccount::query()
            ->where('user_id', $user->id)
            ->withCount(['conversations as conversation_count' => fn (Builder $query) => $query->where('is_member', true)])
            ->orderBy('team_name')
            ->get()
            ->map(fn (SlackAccount $account): ChatAccountData => new ChatAccountData(
                id: $account->friendly_id,
                provider: ChatProvider::SLACK,
                label: $account->team_name,
                detail: $account->team_domain === null ? null : "{$account->team_domain}.slack.com",
                isUsable: $account->isUsable(),
                error: $this->accountError($account),
                connectedAt: CarbonImmutable::instance($account->connected_at),
                syncedAt: $account->synced_at === null ? null : CarbonImmutable::instance($account->synced_at),
                url: $account->url(),
                conversationCount: (int) $account->getAttribute('conversation_count'),
            ))
            ->toBase();
    }

    public function messages(ChatConversation $conversation, ?string $beforeId, int $limit): Collection
    {
        $slack = $this->slackOf($conversation);
        $query = $slack->messages()->topLevel();

        if ($beforeId !== null) {
            $before = $this->rowOf($slack, $beforeId);
            $query->where('ts', '<', $before->ts);
        }

        $rows = $query->orderByDesc('ts')->limit($limit)->get()->reverse()->values();

        return $this->toData($slack, $rows);
    }

    public function hasOlderMessages(ChatConversation $conversation, string $oldestId): bool
    {
        $slack = $this->slackOf($conversation);
        $oldest = $this->rowOf($slack, $oldestId);

        if ($slack->messages()->topLevel()->where('ts', '<', $oldest->ts)->exists()) {
            return true;
        }

        return $slack->history_exhausted_at === null && $slack->account->isUsable();
    }

    public function message(ChatConversation $conversation, string $messageId): ?ChatMessageData
    {
        $slack = $this->slackOf($conversation);
        $row = $slack->messages()->where('friendly_id', $messageId)->first();

        return $row === null ? null : $this->toData($slack, new EloquentCollection([$row]))->first();
    }

    public function thread(ChatConversation $conversation, string $messageId): Collection
    {
        $slack = $this->slackOf($conversation);
        $parent = $this->rowOf($slack, $messageId);
        $threadTs = $parent->thread_ts ?? $parent->ts;

        $known = $slack->messages()->where('thread_ts', $threadTs)->where('ts', '!=', $threadTs)->count();

        if ($known < $parent->reply_count && $slack->account->isUsable()) {
            $this->guard($slack->account, function () use ($slack, $threadTs): void {
                $replies = SlackApiClient::forAccount($slack->account)->replies($slack->channel_id, $threadTs);
                ($this->recordMessages)($slack->account, $slack, $replies, false);
            });
        }

        $rows = $slack->messages()
            ->where(fn (Builder $query) => $query->where('ts', $threadTs)->orWhere('thread_ts', $threadTs))
            ->orderBy('ts')
            ->get();

        return $this->toData($slack, $rows);
    }

    public function participants(ChatConversation $conversation): Collection
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;
        $client = $account->isUsable() ? SlackApiClient::forAccount($account) : null;

        if ($slack->isDirect()) {
            $ids = array_values(array_filter([$account->slack_user_id, $slack->counterpart_user_id]));
        } elseif ($client === null) {
            $ids = array_values(array_filter($slack->messages()->whereNotNull('slack_user_id')->distinct()->pluck('slack_user_id')->all(), 'is_string'));
        } else {
            $ids = array_values(array_filter((array) Cache::remember(
                "slack:participants:{$slack->id}",
                self::PARTICIPANTS_CACHE_SECONDS,
                fn (): array => $this->guard($account, fn (): array => $client->conversationMembers($slack->channel_id)),
            ), 'is_string'));
        }

        return $this->directory->usersOf($account, $ids, $client)
            ->map(fn (SlackUser $user): ChatParticipantData => new ChatParticipantData(
                id: $user->slack_user_id,
                name: $user->displayName(),
                handle: $user->name,
                pictureUrl: $user->picture_url,
                isMe: $user->slack_user_id === $account->slack_user_id,
            ))
            ->sortBy(fn (ChatParticipantData $participant): string => mb_strtolower($participant->name))
            ->values()
            ->toBase();
    }

    public function send(ChatConversation $conversation, OutgoingChatMessage $message): ChatMessageData
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;

        if (! $this->isWritable($slack)) {
            throw new ChatProviderException($this->readOnlyReason($slack));
        }

        if ($message->isEmpty()) {
            throw new ChatProviderException(__('There is nothing to send.'));
        }

        $client = SlackApiClient::forAccount($account);
        $text = trim($message->text) === '' ? '' : SlackMessageFormat::outgoing($message->text, $this->mentionableIn($account, $message->text));
        $threadTs = $message->threadId === null ? null : $this->threadTsOf($slack, $message->threadId);

        $recorded = $this->guard($account, function () use ($client, $slack, $account, $message, $text, $threadTs): EloquentCollection {
            if ($message->files === []) {
                $posted = $client->postMessage($slack->channel_id, $text, $threadTs);

                return ($this->recordMessages)($account, $slack, [$posted], false);
            }

            $fileIds = [];

            foreach ($message->files as $index => $file) {
                $contents = (string) file_get_contents($file->path);
                $uploaded = $client->uploadFile($slack->channel_id, $contents, $file->name, $threadTs, $index === 0 ? $text : null);
                $fileIds[] = (string) ($uploaded['id'] ?? '');
            }

            $latest = $threadTs === null
                ? $client->history($slack->channel_id, null, 10)['messages']
                : array_reverse($client->replies($slack->channel_id, $threadTs, 50));

            $recorded = ($this->recordMessages)($account, $slack, $latest, false);

            return $recorded
                ->filter(fn (SlackMessage $row): bool => array_intersect($fileIds, array_column($row->files ?? [], 'id')) !== [])
                ->sortByDesc('ts')
                ->take(1)
                ->values();
        });

        $row = $recorded->sortByDesc('ts')->first();

        if ($row === null) {
            throw new ChatProviderException(__('Slack took the message but did not hand it back. Reload the conversation.'));
        }

        $conversation->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();

        return $this->toData($slack, new EloquentCollection([$row]))->first();
    }

    public function markRead(ChatConversation $conversation): void
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;
        $latest = $slack->messages()->topLevel()->orderByDesc('ts')->first();

        if ($latest === null || ! $account->isUsable() || $slack->last_read === $latest->ts) {
            return;
        }

        try {
            $this->guard($account, fn () => SlackApiClient::forAccount($account)->markRead($slack->channel_id, $latest->ts));
        } catch (ChatProviderException $exception) {
            report($exception);

            return;
        }

        $slack->forceFill(['last_read' => $latest->ts])->save();
    }

    public function react(ChatConversation $conversation, string $messageId, string $emoji, bool $on): void
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;

        if (! $this->isWritable($slack)) {
            throw new ChatProviderException($this->readOnlyReason($slack));
        }

        $name = SlackEmoji::nameOf($emoji);

        if ($name === null) {
            throw new ChatProviderException(__('Slack has no name for this emoji.'));
        }

        $row = $this->rowOf($slack, $messageId);
        $client = SlackApiClient::forAccount($account);

        $this->guard($account, fn () => $on
            ? $client->addReaction($slack->channel_id, $row->ts, $name)
            : $client->removeReaction($slack->channel_id, $row->ts, $name));

        $this->recordMessages->reacted($account, $slack, $row->ts, $name, $account->slack_user_id, $on);
    }

    public function edit(ChatConversation $conversation, string $messageId, string $text): ChatMessageData
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;
        $row = $this->rowOf($slack, $messageId);

        if (! $this->isWritable($slack)) {
            throw new ChatProviderException($this->readOnlyReason($slack));
        }

        if (! $this->isMine($account, $row) || $row->isDeleted()) {
            throw new ChatProviderException(__('Only your own messages can be edited.'));
        }

        $outgoing = SlackMessageFormat::outgoing($text, $this->mentionableIn($account, $text));

        $this->guard($account, fn () => SlackApiClient::forAccount($account)->updateMessage($slack->channel_id, $row->ts, $outgoing));

        $row->forceFill(['text' => $outgoing, 'edited_at' => now()])->save();
        $this->recordMessages->touchEntry($account, $slack, $conversation);
        ChatConversationUpdated::dispatch($conversation->user_id, $conversation->friendly_id, ChatProvider::SLACK, $row->friendly_id);

        return $this->toData($slack, new EloquentCollection([$row]))->first();
    }

    public function delete(ChatConversation $conversation, string $messageId): void
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;
        $row = $this->rowOf($slack, $messageId);

        if (! $this->isWritable($slack)) {
            throw new ChatProviderException($this->readOnlyReason($slack));
        }

        if (! $this->isMine($account, $row)) {
            throw new ChatProviderException(__('Only your own messages can be deleted.'));
        }

        $this->guard($account, fn () => SlackApiClient::forAccount($account)->deleteMessage($slack->channel_id, $row->ts));

        $this->recordMessages->deleted($account, $slack, $row->ts);
    }

    public function refresh(ChatConversation $conversation, ?string $olderThanId = null): void
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;

        if (! $account->isUsable()) {
            throw new ChatProviderException($this->accountError($account) ?? __('This Slack workspace needs to be connected again.'));
        }

        $client = SlackApiClient::forAccount($account);

        $this->guard($account, function () use ($client, $slack, $account, $olderThanId): void {
            if ($olderThanId === null) {
                $channel = $client->conversationInfo($slack->channel_id);
                $slack = ($this->ensureConversation)($account, $channel, $client);

                $history = $client->history($slack->channel_id, null, 50);
                ($this->recordMessages)($account, $slack, $history['messages'], false);

                $slack->forceFill([
                    'history_loaded_at' => now(),
                    'history_exhausted_at' => $history['has_more'] ? $slack->history_exhausted_at : now(),
                ])->save();

                return;
            }

            $oldest = $this->rowOf($slack, $olderThanId);
            $history = $client->history($slack->channel_id, $oldest->ts, 50);
            ($this->recordMessages)($account, $slack, $history['messages'], false);

            if (! $history['has_more']) {
                $slack->forceFill(['history_exhausted_at' => now()])->save();
            }
        });
    }

    public function file(ChatConversation $conversation, string $messageId, string $fileId): ?ChatFileContents
    {
        $slack = $this->slackOf($conversation);
        $account = $slack->account;
        $row = $this->rowOf($slack, $messageId);

        $file = (new Collection($row->files ?? []))->firstWhere('id', $fileId);

        if ($file === null) {
            return null;
        }

        $url = $file['url_private_download'] ?? $file['url_private'] ?? null;

        if (! is_string($url) || ! $account->isUsable()) {
            return null;
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_FILE_BYTES) {
            throw new ChatProviderException(__('This file is too large to show here. Open it on Slack.'));
        }

        $response = $this->guard($account, fn () => SlackApiClient::forAccount($account)->downloadFile($url));

        return new ChatFileContents(
            contents: $response->body(),
            name: (string) ($file['name'] ?? $file['title'] ?? $fileId),
            mime: (string) ($file['mimetype'] ?? $response->header('Content-Type') ?: 'application/octet-stream'),
        );
    }

    public function styleSamples(User $user, ChatConversation $except, int $limit): array
    {
        $exceptId = $except->conversable_type === 'slack_conversation' ? $except->conversable_id : null;

        $samples = SlackMessage::query()
            ->select('slack_messages.text')
            ->join('slack_conversations', 'slack_conversations.id', '=', 'slack_messages.slack_conversation_id')
            ->join('slack_accounts', 'slack_accounts.id', '=', 'slack_conversations.slack_account_id')
            ->where('slack_accounts.user_id', $user->id)
            ->whereColumn('slack_messages.slack_user_id', 'slack_accounts.slack_user_id')
            ->when($exceptId !== null, fn (Builder $query) => $query->where('slack_conversations.id', '!=', $exceptId))
            ->whereNull('slack_messages.deleted_at')
            ->whereNull('slack_messages.subtype')
            ->whereNotNull('slack_messages.text')
            ->where('slack_messages.text', '!=', '')
            ->orderByDesc('slack_messages.sent_at')
            ->limit($limit)
            ->pluck('slack_messages.text')
            ->map(fn (string $text): string => trim(SlackMessageFormat::toPlain($text)))
            ->filter()
            ->values()
            ->all();

        return array_values($samples);
    }

    public function externalUrl(ChatConversation $conversation): ?string
    {
        return $this->slackOf($conversation)->url();
    }

    /**
     * Slack keeps none of the entry's state: pins, archives and the rest
     * are Jarvis' own.
     */
    public function entryChanged(ChatConversation $conversation, array $changes): void {}

    /**
     * The rows as the screens take them, names and pictures of the senders
     * and of the people mentioned or reacting looked up once.
     *
     * @param  EloquentCollection<int, SlackMessage>  $rows
     * @return Collection<int, ChatMessageData>
     */
    private function toData(SlackConversation $slack, EloquentCollection $rows): Collection
    {
        $account = $slack->account;
        $entry = $slack->conversation;
        $conversationId = $entry->friendly_id ?? '';
        $writable = $this->isWritable($slack);

        $userIds = $rows->flatMap(fn (SlackMessage $row): array => [
            $row->slack_user_id,
            ...($row->text === null ? [] : RecordSlackMessages::mentionedIn($row->text)),
            ...array_merge([], ...array_map(fn (array $reaction): array => $reaction['users'], $row->reactions ?? [])),
        ])->filter()->unique()->values()->all();
        $userIds = array_values($userIds);

        $users = $this->directory->usersOf($account, $userIds, $account->isUsable() ? SlackApiClient::forAccount($account) : null);
        $names = $users->map(fn (SlackUser $user): string => $user->displayName())->all();

        $threadTs = $rows->pluck('thread_ts')->filter()->unique()->values()->all();
        $parentIds = $threadTs === [] ? [] : $slack->messages()->whereIn('ts', $threadTs)->pluck('friendly_id', 'ts')->all();

        return $rows->map(function (SlackMessage $row) use ($slack, $account, $entry, $conversationId, $writable, $users, $names, $parentIds): ChatMessageData {
            $mine = $this->isMine($account, $row);
            $sender = $row->slack_user_id === null ? null : $users->get($row->slack_user_id);
            $text = $row->text === null ? null : trim(SlackMessageFormat::toPlain($row->text, $names));

            return new ChatMessageData(
                id: $row->friendly_id,
                conversationId: $conversationId,
                fromMe: $mine,
                senderId: $row->slack_user_id ?? $row->bot_id,
                senderName: $sender?->displayName() ?? ($row->bot_id === null ? $row->slack_user_id : (string) __('App')),
                senderPictureUrl: $sender?->picture_url,
                kind: $row->isSystem() ? 'system' : (filled($row->files) ? 'file' : 'text'),
                text: $text === '' ? null : $text,
                html: $row->text === null ? '' : SlackMessageFormat::toHtml($row->text, $names)->toHtml(),
                files: $entry === null ? [] : $this->filesOf($entry, $row),
                threadId: $row->thread_ts === null ? null : ($parentIds[$row->thread_ts] ?? null),
                replyCount: $row->reply_count,
                reactions: $this->reactionsOf($account, $row, $names),
                sentAt: CarbonImmutable::instance($row->sent_at),
                editedAt: $row->edited_at === null ? null : CarbonImmutable::instance($row->edited_at),
                deletedAt: $row->deleted_at === null ? null : CarbonImmutable::instance($row->deleted_at),
                canEdit: $writable && $mine && ! $row->isDeleted() && ! $row->isSystem() && blank($row->files),
                canDelete: $writable && $mine && ! $row->isDeleted() && ! $row->isSystem(),
                externalUrl: $this->permalink($slack, $row),
            );
        })->toBase();
    }

    /**
     * @return list<ChatFileData>
     */
    private function filesOf(ChatConversation $entry, SlackMessage $row): array
    {
        $files = [];

        foreach ($row->files ?? [] as $file) {
            $id = (string) ($file['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $mime = isset($file['mimetype']) ? (string) $file['mimetype'] : null;
            $servable = isset($file['url_private']) && (int) ($file['size'] ?? 0) <= self::MAX_FILE_BYTES;

            $files[] = new ChatFileData(
                id: $id,
                name: (string) ($file['title'] ?? $file['name'] ?? $id),
                mime: $mime,
                size: isset($file['size']) ? (int) $file['size'] : null,
                kind: ChatFileData::kindOf($mime),
                url: $servable ? $this->fileUrl($entry, $row, $id) : (isset($file['permalink']) ? (string) $file['permalink'] : null),
            );
        }

        return $files;
    }

    /**
     * A temporary signed link to the file, good for the web and the apps.
     */
    private function fileUrl(ChatConversation $entry, SlackMessage $row, string $fileId): string
    {
        return URL::temporarySignedRoute('api.chats.file', now()->addMinutes(self::FILE_URL_MINUTES), [
            'conversation' => $entry->friendly_id,
            'message' => $row->friendly_id,
            'file' => $fileId,
        ]);
    }

    /**
     * @param  array<string, string>  $names
     * @return list<ChatReactionData>
     */
    private function reactionsOf(SlackAccount $account, SlackMessage $row, array $names): array
    {
        $reactions = [];

        foreach ($row->reactions ?? [] as $reaction) {
            $users = $reaction['users'];

            if ($users === []) {
                continue;
            }

            $reactions[] = new ChatReactionData(
                emoji: SlackEmoji::characterOf((string) $reaction['name']) ?? ':'.$reaction['name'].':',
                count: count($users),
                mine: in_array($account->slack_user_id, $users, true),
                names: array_map(fn (string $id): string => $names[$id] ?? $id, $users),
            );
        }

        return $reactions;
    }

    private function permalink(SlackConversation $slack, SlackMessage $row): ?string
    {
        $base = $slack->url();

        return $base === null ? null : $base.'/p'.str_replace('.', '', $row->ts);
    }

    /**
     * The `@handle`s the text mentions that are people of the workspace:
     * handle => Slack user id.
     *
     * @return array<string, string>
     */
    private function mentionableIn(SlackAccount $account, string $text): array
    {
        preg_match_all('/(?<![\w@])@([\w.\-]+)/u', $text, $matches);
        $handles = array_values(array_unique(array_map('mb_strtolower', $matches[1])));

        if ($handles === []) {
            return [];
        }

        return $account->members()
            ->whereIn('name', $handles)
            ->where('is_deleted', false)
            ->pluck('slack_user_id', 'name')
            ->all();
    }

    private function threadTsOf(SlackConversation $slack, string $messageId): string
    {
        $parent = $this->rowOf($slack, $messageId);

        return $parent->thread_ts ?? $parent->ts;
    }

    private function isMine(SlackAccount $account, SlackMessage $row): bool
    {
        return $row->slack_user_id !== null && $row->slack_user_id === $account->slack_user_id;
    }

    private function isWritable(SlackConversation $slack): bool
    {
        return $slack->account->isUsable() && ! $slack->is_archived && $slack->is_member;
    }

    private function readOnlyReason(SlackConversation $slack): string
    {
        return match (true) {
            ! $slack->account->isUsable() => $this->accountError($slack->account) ?? __('This Slack workspace needs to be connected again.'),
            $slack->is_archived => __('This conversation is archived on Slack.'),
            default => __('You are no longer in this conversation on Slack.'),
        };
    }

    private function accountError(SlackAccount $account): ?string
    {
        if (! $account->hasFailed()) {
            return null;
        }

        return match ($account->last_error) {
            'app_uninstalled' => __('Jarvis was removed from this Slack workspace. Connect it again.'),
            'missing_scope' => __('Slack needs new permissions. Connect the workspace again.'),
            default => __('Slack no longer accepts the connection. Connect the workspace again.'),
        };
    }

    /**
     * The SlackConversation of an entry of the chat screen.
     */
    private function slackOf(ChatConversation $conversation): SlackConversation
    {
        $slack = $conversation->conversable;

        if (! $slack instanceof SlackConversation) {
            throw new InvalidArgumentException('The conversation is not a Slack one.');
        }

        $slack->loadMissing('account');
        $slack->setRelation('conversation', $conversation);

        return $slack;
    }

    /**
     * @throws ChatProviderException for a message id not of the conversation
     */
    private function rowOf(SlackConversation $slack, string $messageId): SlackMessage
    {
        $row = $slack->messages()->where('friendly_id', $messageId)->first();

        if ($row === null) {
            throw new ChatProviderException(__('This message is no longer here.'));
        }

        return $row;
    }

    /**
     * Runs a Slack call, turning its failure into one the screens show and
     * marking the account failed when the token is no good any more.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws ChatProviderException
     */
    private function guard(SlackAccount $account, callable $call): mixed
    {
        try {
            return $call();
        } catch (SlackApiException $exception) {
            if ($exception->isTokenInvalid()) {
                $account->forceFill(['failed_at' => now(), 'last_error' => $exception->error])->save();

                throw new ChatProviderException($this->accountError($account) ?? __('Slack no longer accepts the connection.'), $exception);
            }

            if ($exception->isRateLimited()) {
                throw new ChatProviderException(__('Slack asked to slow down. Try again in :seconds seconds.', ['seconds' => $exception->retryAfter ?? 60]), $exception);
            }

            if ($exception->isNotFound()) {
                throw new ChatProviderException(__('Slack no longer has this.'), $exception);
            }

            report($exception);

            throw new ChatProviderException(__('Slack could not do that right now. Try again in a moment.'), $exception);
        }
    }
}
