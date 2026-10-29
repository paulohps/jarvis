<?php

namespace App\Actions\Slack;

use App\Events\ChatConversationUpdated;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\ChatMessageReceived;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackApiException;
use App\Services\Slack\SlackMessageFormat;
use App\Services\Slack\SlackUserDirectory;
use App\Support\FriendlyId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * Keeps the messages of a Slack conversation as the Events API and the
 * history hand them over (message objects with `ts`): inserts the new
 * ones and updates the known ones by `(conversation, ts)`, so a message
 * seen twice is recorded once.
 *
 * `$live` is for what just happened (an event), not the history: the
 * conversation's entry on the chat screen then counts the unread ones
 * among the messages not seen before (reset by a message of the user's
 * own, as Slack does), gets its last
 * message, tells the screen (ChatConversationUpdated) and pushes the
 * latest received one to the phone, unless the conversation is muted.
 * The history only refreshes the entry's last message.
 *
 * `changed()`, `deleted()` and `reacted()` apply the matching events.
 */
final class RecordSlackMessages
{
    private const int CHUNK = 200;

    /**
     * The keys of a file object kept with the message.
     */
    private const array FILE_KEYS = ['id', 'name', 'title', 'mimetype', 'filetype', 'size', 'url_private', 'url_private_download', 'thumb_80', 'thumb_360', 'thumb_360_w', 'thumb_360_h', 'permalink', 'mode'];

    public function __construct(
        private readonly SlackUserDirectory $directory,
        private readonly NotificationCenter $notificationCenter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return Collection<int, SlackMessage> the rows of the messages given
     */
    public function __invoke(SlackAccount $account, SlackConversation $conversation, array $messages, bool $live): Collection
    {
        $rows = [];

        foreach ($messages as $message) {
            $row = $this->row($conversation, $message);

            if ($row !== null) {
                $rows[$row['ts']] = $row;
            }
        }

        if ($rows === []) {
            return new Collection;
        }

        $known = $conversation->messages()->whereIn('ts', array_keys($rows))->pluck('ts')->all();

        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            SlackMessage::query()->upsert(
                $chunk,
                ['slack_conversation_id', 'ts'],
                ['thread_ts', 'slack_user_id', 'bot_id', 'subtype', 'text', 'files', 'reactions', 'reply_count', 'edited_at', 'sent_at', 'updated_at'],
            );
        }

        $recorded = $conversation->messages()->whereIn('ts', array_keys($rows))->get();
        $new = $recorded->reject(fn (SlackMessage $message): bool => in_array($message->ts, $known, true));
        $entry = $this->entryOf($conversation);

        if ($entry !== null) {
            if ($live) {
                $this->countUnread($account, $conversation, $entry, $new);
            }

            $this->touchEntry($account, $conversation, $entry);
            $this->announce($entry, $live ? $recorded->sortByDesc('sent_at')->first()?->friendly_id : null);

            if ($live) {
                $this->notifyReceived($account, $conversation, $entry, $new);
            }
        }

        return $recorded;
    }

    /**
     * A message was edited (`message_changed`): its new text, files and
     * edit time.
     *
     * @param  array<string, mixed>  $message  the message object as it is now
     */
    public function changed(SlackAccount $account, SlackConversation $conversation, array $message): ?SlackMessage
    {
        $row = $this->row($conversation, $message);

        if ($row === null) {
            return null;
        }

        $existing = $conversation->messages()->where('ts', $row['ts'])->first();

        if ($existing === null) {
            return $this->__invoke($account, $conversation, [$message], false)->first();
        }

        $existing->fill(Arr::only($row, ['text', 'files', 'reactions', 'reply_count', 'edited_at', 'thread_ts', 'subtype']))->save();

        $entry = $this->entryOf($conversation);

        if ($entry !== null) {
            $this->touchEntry($account, $conversation, $entry);
            $this->announce($entry, $existing->friendly_id);
        }

        return $existing;
    }

    /**
     * A message was deleted (`message_deleted`): the row stays, marked.
     */
    public function deleted(SlackAccount $account, SlackConversation $conversation, string $ts): void
    {
        $message = $conversation->messages()->where('ts', $ts)->first();

        if ($message === null) {
            return;
        }

        $message->forceFill(['deleted_at' => now()])->save();

        $entry = $this->entryOf($conversation);

        if ($entry !== null) {
            $this->touchEntry($account, $conversation, $entry);
            $this->announce($entry, $message->friendly_id);
        }
    }

    /**
     * Someone reacted (`$on`) or took a reaction back.
     */
    public function reacted(SlackAccount $account, SlackConversation $conversation, string $ts, string $name, string $userId, bool $on): void
    {
        $message = $conversation->messages()->where('ts', $ts)->first();

        if ($message === null) {
            return;
        }

        $reactions = (new \Illuminate\Support\Collection($message->reactions ?? []))->keyBy('name');
        $users = array_values(array_diff((array) ($reactions->get($name)['users'] ?? []), [$userId]));

        if ($on) {
            $users[] = $userId;
        }

        if ($users === []) {
            $reactions->forget($name);
        } else {
            $reactions->put($name, ['name' => $name, 'users' => $users]);
        }

        $message->forceFill(['reactions' => $reactions->values()->all()])->save();

        $entry = $this->entryOf($conversation);

        if ($entry !== null) {
            $this->announce($entry, $message->friendly_id);
        }
    }

    /**
     * Refreshes the entry's last message from what is recorded.
     */
    public function touchEntry(SlackAccount $account, SlackConversation $conversation, ChatConversation $entry): void
    {
        $latest = $conversation->messages()->orderByDesc('sent_at')->orderByDesc('id')->first();

        if ($latest === null) {
            return;
        }

        $senderName = $latest->slack_user_id === null ? null : $this->namesOf($account, [$latest->slack_user_id])[$latest->slack_user_id] ?? null;
        $fromMe = $latest->slack_user_id !== null && $latest->slack_user_id === $account->slack_user_id;

        $entry->forceFill([
            'last_message_at' => $latest->sent_at,
            'last_message' => [
                'text' => $this->previewOf($account, $latest),
                'from_me' => $fromMe,
                'sender' => $fromMe ? null : $senderName,
                'kind' => $latest->isDeleted() ? 'deleted' : ($latest->isSystem() ? 'system' : (filled($latest->files) ? 'file' : 'text')),
            ],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function row(SlackConversation $conversation, array $message): ?array
    {
        $ts = $message['ts'] ?? null;

        if (! is_string($ts) || $ts === '') {
            return null;
        }

        $files = [];

        foreach ((array) ($message['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['id'])) {
                $files[] = Arr::only($file, self::FILE_KEYS);
            }
        }

        $reactions = [];

        foreach ((array) ($message['reactions'] ?? []) as $reaction) {
            if (is_array($reaction) && isset($reaction['name'])) {
                $reactions[] = ['name' => (string) $reaction['name'], 'users' => array_values(array_map('strval', (array) ($reaction['users'] ?? [])))];
            }
        }

        $edited = $message['edited']['ts'] ?? null;
        $now = now();

        return [
            'friendly_id' => FriendlyId::generate(SlackMessage::idPrefix()),
            'slack_conversation_id' => $conversation->id,
            'ts' => $ts,
            'thread_ts' => isset($message['thread_ts']) ? (string) $message['thread_ts'] : null,
            'slack_user_id' => isset($message['user']) ? (string) $message['user'] : null,
            'bot_id' => isset($message['bot_id']) ? (string) $message['bot_id'] : null,
            'subtype' => filled($message['subtype'] ?? null) ? (string) $message['subtype'] : null,
            'text' => isset($message['text']) ? (string) $message['text'] : null,
            'files' => $files === [] ? null : json_encode($files, JSON_THROW_ON_ERROR),
            'reactions' => $reactions === [] ? null : json_encode($reactions, JSON_THROW_ON_ERROR),
            'reply_count' => (int) ($message['reply_count'] ?? 0),
            'edited_at' => is_string($edited) ? SlackMessage::instantOf($edited) : null,
            'sent_at' => SlackMessage::instantOf($ts),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  Collection<int, SlackMessage>  $recorded
     */
    private function countUnread(SlackAccount $account, SlackConversation $conversation, ChatConversation $entry, Collection $recorded): void
    {
        $received = $recorded->filter(fn (SlackMessage $message): bool => ! $message->isSystem() && $message->slack_user_id !== $account->slack_user_id);
        $own = $recorded->contains(fn (SlackMessage $message): bool => $message->slack_user_id === $account->slack_user_id);

        if ($own) {
            $entry->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();
        } elseif ($received->isNotEmpty()) {
            $entry->forceFill(['unread_count' => $entry->unread_count + $received->count()])->save();
        }
    }

    /**
     * @param  Collection<int, SlackMessage>  $recorded
     */
    private function notifyReceived(SlackAccount $account, SlackConversation $conversation, ChatConversation $entry, Collection $recorded): void
    {
        if ($entry->isMuted()) {
            return;
        }

        /** @var SlackMessage|null $latest */
        $latest = $recorded
            ->filter(fn (SlackMessage $message): bool => ! $message->isSystem() && ! $message->isDeleted() && $message->slack_user_id !== $account->slack_user_id)
            ->sortByDesc('sent_at')
            ->first();

        if ($latest === null) {
            return;
        }

        $senderName = $latest->slack_user_id === null ? null : ($this->namesOf($account, [$latest->slack_user_id])[$latest->slack_user_id] ?? null);

        $entry->setRelation('provider', ChatProvider::bySlug(ChatProvider::SLACK));

        $this->notificationCenter->dispatch(
            new ChatMessageReceived($entry, $senderName, $this->previewOf($account, $latest)),
            $account->user,
        );
    }

    private function previewOf(SlackAccount $account, SlackMessage $message): string
    {
        if ($message->isDeleted()) {
            return __('Message deleted');
        }

        $names = $message->text === null ? [] : $this->namesOf($account, self::mentionedIn($message->text));
        $text = $message->text === null ? '' : trim(SlackMessageFormat::toPlain($message->text, $names));

        if ($text !== '') {
            return $text;
        }

        $file = $message->files[0] ?? null;

        if ($file !== null) {
            return (string) ($file['title'] ?? $file['name'] ?? __('File'));
        }

        return __('Message');
    }

    /**
     * The names of the ids, fetched from Slack when not synced yet and the
     * token still works; an id stays an id when Slack cannot be asked.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    private function namesOf(SlackAccount $account, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        try {
            return $this->directory->namesOf($account, $userIds, $account->isUsable() ? SlackApiClient::forAccount($account) : null);
        } catch (SlackApiException) {
            return $this->directory->namesOf($account, $userIds);
        }
    }

    /**
     * The user ids a text mentions.
     *
     * @return list<string>
     */
    public static function mentionedIn(string $text): array
    {
        preg_match_all('/<@([A-Z0-9]+)(?:\|[^>]*)?>/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function entryOf(SlackConversation $conversation): ?ChatConversation
    {
        $entry = $conversation->conversation;

        if ($entry === null) {
            return null;
        }

        $entry->setRelation('provider', ChatProvider::bySlug(ChatProvider::SLACK));

        return $entry;
    }

    private function announce(ChatConversation $entry, ?string $messageId): void
    {
        ChatConversationUpdated::dispatch($entry->user_id, $entry->friendly_id, ChatProvider::SLACK, $messageId);
    }
}
