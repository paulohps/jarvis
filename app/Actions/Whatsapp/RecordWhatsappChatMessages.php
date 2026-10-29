<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReaction;
use App\Models\WhatsappPollVote;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\WhatsappMessageReceived;
use App\Support\FriendlyId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Keeps the messages of an account as the Evolution API reports them
 * (`messages.upsert` live, `messages.set` with the history WhatsApp hands
 * over on linking), for the WhatsApp screen and the reply keyboard of the
 * apps. Each message is a Baileys `WAMessage`: `{key: {remoteJid,
 * remoteJidAlt?, fromMe, id, participant?, participantAlt?,
 * participantPn?}, pushName, message, messageType, messageTimestamp}`.
 *
 * - Broadcasts, newsletters and status updates are dropped, as are the
 *   messages without content of their own (reactions, edits, receipts…),
 *   though what they do is kept: a reaction (react()), an edit (edit()),
 *   a pin for everyone (pin()), a poll vote (vote()) or a new timer of
 *   disappearing messages (`ephemeral_seconds` of the chat).
 * - A one-to-one chat is the contact's WhatsappTarget: the phone JID when
 *   WhatsApp gives one (`remoteJidAlt` for an `@lid` chat), otherwise the
 *   `@lid` JID. A contact not synced yet is added, named after the push
 *   name of what it sent; a target without a name takes it.
 *   A contact known by its phone JID keeps the `@lid` one of the key
 *   (`lid`), which presence updates may come with.
 * - A group chat (`@g.us`) is the group's WhatsappTarget, added without a
 *   name when not synced yet ("Sync contacts and groups" names it). Each
 *   received message keeps its sender (`participant`, the phone JID when
 *   WhatsApp gives one) and the push name it showed, or else the name of
 *   the account's contact with that JID.
 * - A message already kept (same chat, same WhatsApp id) is left as is, so
 *   a history replayed or a webhook retried changes nothing, except that
 *   what the number sent takes its furthest receipt (the ticks: `status`
 *   and `MessageUpdate`, WhatsappChatMessage::receiptOf()), which only
 *   goes up.
 * - A message deleted for everyone is marked (`revoked_at`) and kept, so
 *   the user still sees what it said: by a `protocolMessage` of type
 *   REVOKE, or an update with status DELETED (`MessageUpdate`, as the
 *   Evolution API keeps it). See also revoke().
 * - A media keeps what WhatsApp says about it (type, size, file name, the
 *   length and waveform of an audio, the tiny preview of an image or
 *   video); its
 *   file is fetched only when the user downloads it
 *   (WhatsappChatMediaController).
 * - Each chat keeps when it last had a message (`last_message_at`). With
 *   `$live` (a message as it happens, not a history), what the contact sent
 *   adds to `unread_count`, what the number sent from any device sets it
 *   back to 0 (as WhatsApp does), and the owner's screens are told
 *   (`WhatsappChatUpdated`); the latest message the contact sent is pushed
 *   to the owner's apps (`WhatsappMessageReceived`) when the chat notifies
 *   messages.
 *
 * Returns how many messages were new.
 */
final readonly class RecordWhatsappChatMessages
{
    private const string LID_SUFFIX = '@lid';

    private const string GROUP_SUFFIX = '@g.us';

    private const int INSERT_CHUNK = 500;

    /**
     * Message types that carry no content of their own.
     *
     * @var list<string>
     */
    private const array IGNORED_TYPES = [
        'protocolMessage', 'reactionMessage', 'senderKeyDistributionMessage', 'messageContextInfo',
        'pollUpdateMessage', 'editedMessage', 'keepInChatMessage', 'pinInChatMessage', 'encReactionMessage',
        // The encrypted envelope of an edit or of an answer to an event: the edit itself comes as a messages update.
        'secretEncryptedMessage', 'encEventResponseMessage',
    ];

    public function __construct(private NotificationCenter $center) {}

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __invoke(WhatsappAccount $account, array $messages, bool $live = false): int
    {
        /** @var array<string, array{name: string|null, rows: list<array<string, mixed>>}> $chats */
        $chats = [];
        $revokedIds = [];
        $reactions = [];
        $edits = [];
        $pins = [];
        $votes = [];
        $timers = [];

        foreach ($messages as $message) {
            $revokedId = $this->revokedId($message);

            if ($revokedId !== null) {
                $revokedIds[] = $revokedId;
            }

            $reaction = $this->reactionOf($message);

            if ($reaction !== null) {
                $reactions[] = $reaction;
            }

            $content = is_array($message['message'] ?? null) ? $message['message'] : [];
            $protocol = $content['protocolMessage'] ?? $content['editedMessage']['message']['protocolMessage'] ?? null;
            $edit = is_array($protocol) ? $this->editOf($protocol) : null;
            $timer = is_array($protocol) ? $this->timerOf($message, $protocol) : null;
            $pin = $this->pinOf($message);
            $vote = $this->voteOf($message);

            if ($edit !== null) {
                $edits[] = $edit;
            }

            if ($timer !== null) {
                $timers[] = $timer;
            }

            if ($pin !== null) {
                $pins[] = $pin;
            }

            if ($vote !== null) {
                $votes[] = $vote;
            }

            $parsed = $this->parse($message);

            if ($parsed === null) {
                continue;
            }

            $chats[$parsed['jid']] ??= ['name' => null, 'lid' => null, 'rows' => []];
            $chats[$parsed['jid']]['lid'] ??= $parsed['lid'];
            $chats[$parsed['jid']]['name'] ??= $parsed['from_me'] || $parsed['is_group'] ? null : $parsed['push_name'];
            $chats[$parsed['jid']]['rows'][] = $parsed['row'];
        }

        $chats = $this->withSenderNames($account, $chats);
        $recorded = 0;

        foreach ($chats as $jid => $chat) {
            $target = $this->targetFor($account, $jid, $chat['name'], $chat['lid']);
            $now = now();

            $rows = array_map(fn (array $row): array => [
                ...$row,
                'friendly_id' => FriendlyId::generate(WhatsappChatMessage::idPrefix()),
                'whatsapp_target_id' => $target->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chat['rows']);

            $received = $this->insert(array_filter($rows, fn (array $row): bool => ! $row['from_me']));
            $sent = $this->insert(array_filter($rows, fn (array $row): bool => $row['from_me']));
            $recorded += $received + $sent;
            $this->raiseReceipts($target, $rows);

            $this->touch($account, $target, $rows, $live ? $received : 0, $live && $sent > 0);

            if ($live && $received > 0) {
                $this->notifyReceived($account, $target, $rows);
            }
        }

        $this->revoke($account, $revokedIds);
        $this->react($account, $reactions);
        $this->edit($account, $edits);
        $this->pin($account, $pins);
        $this->vote($account, $votes);
        $this->setTimers($account, $timers);

        return $recorded;
    }

    /**
     * Gives the account's messages the text they were edited to (an older
     * edit never undoes a newer one), and tells the screens of their chats.
     *
     * @param  list<array{id: string, body: string, at: CarbonImmutable}>  $edits
     */
    public function edit(WhatsappAccount $account, array $edits): void
    {
        $chatIds = [];

        foreach ($edits as $edit) {
            $message = $this->messageOf($account, $edit['id']);

            if ($message === null || ($message->edited_at !== null && $message->edited_at->greaterThan($edit['at']))) {
                continue;
            }

            $message->forceFill(['body' => $edit['body'], 'edited_at' => $edit['at']])->save();
            $chatIds[$message->target->friendly_id] = true;
        }

        $this->tell($account, array_keys($chatIds));
    }

    /**
     * The edit a `protocolMessage` of type MESSAGE_EDIT carries (as in
     * `messages.upsert`, or the whole of a `messages.edited`): the WhatsApp
     * id of the message edited, its new text and when; null for any other.
     *
     * @param  array<string, mixed>  $protocol
     * @return array{id: string, body: string, at: CarbonImmutable}|null
     */
    public function editOf(array $protocol): ?array
    {
        $id = $protocol['key']['id'] ?? null;
        $edited = $protocol['editedMessage'] ?? null;

        if (! in_array($protocol['type'] ?? null, ['MESSAGE_EDIT', 14], true) || ! is_string($id) || $id === '' || ! is_array($edited)) {
            return null;
        }

        $body = $this->text($this->unwrap($edited));

        return $body === null ? null : ['id' => $id, 'body' => $body, 'at' => $this->millisecondsOr($protocol['timestampMs'] ?? null)];
    }

    /**
     * Pins or unpins the account's messages for everyone in their chats,
     * as WhatsApp tells it (`pinInChatMessage`), and tells the screens.
     *
     * @param  list<array{id: string, pinned: bool, until: CarbonImmutable|null, at: CarbonImmutable}>  $pins
     */
    public function pin(WhatsappAccount $account, array $pins): void
    {
        $chatIds = [];

        foreach ($pins as $pin) {
            $message = $this->messageOf($account, $pin['id']);

            if ($message === null) {
                continue;
            }

            $message->forceFill($pin['pinned']
                ? ['pinned_at' => $pin['at'], 'pinned_until' => $pin['until']]
                : ['pinned_at' => null, 'pinned_until' => null])->save();
            $chatIds[$message->target->friendly_id] = true;
        }

        $this->tell($account, array_keys($chatIds));
    }

    /**
     * The pin a `pinInChatMessage` carries: the WhatsApp id of the message,
     * whether it is pinned (type 1, PIN_FOR_ALL) or unpinned (2), until when
     * (`messageAddOnDurationInSecs`) and since when; null for any other
     * message.
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, pinned: bool, until: CarbonImmutable|null, at: CarbonImmutable}|null
     */
    private function pinOf(array $message): ?array
    {
        $pin = $message['message']['pinInChatMessage'] ?? null;
        $id = is_array($pin) ? ($pin['key']['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $pinned = in_array($pin['type'] ?? null, [1, 'PIN_FOR_ALL'], true);
        $at = $this->millisecondsOr($pin['senderTimestampMs'] ?? null, $message['messageTimestamp'] ?? null);
        $seconds = $message['message']['messageContextInfo']['messageAddOnDurationInSecs'] ?? null;

        return [
            'id' => $id,
            'pinned' => $pinned,
            'until' => $pinned && is_numeric($seconds) && (int) $seconds > 0 ? $at->addSeconds((int) $seconds) : null,
            'at' => $at,
        ];
    }

    /**
     * Keeps what each person voted for in the account's polls (their whole
     * choice each time; none takes the vote back), leaving out what is no
     * option of the poll (an option WhatsApp could not decrypt), and tells
     * the screens.
     *
     * @param  list<array{id: string, voter: string, options: list<string>, at: CarbonImmutable}>  $votes
     */
    public function vote(WhatsappAccount $account, array $votes): void
    {
        $chatIds = [];

        foreach ($votes as $vote) {
            $message = $this->messageOf($account, $vote['id']);

            if ($message === null || ! $message->isPoll()) {
                continue;
            }

            $current = WhatsappPollVote::query()->where('whatsapp_chat_message_id', $message->id)->where('voter', $vote['voter'])->first();

            if ($current !== null && $current->voted_at->greaterThan($vote['at'])) {
                continue;
            }

            // A vote taken back stays, with no option, so an older one arriving late changes nothing.
            WhatsappPollVote::query()->updateOrCreate(
                ['whatsapp_chat_message_id' => $message->id, 'voter' => $vote['voter']],
                ['options' => array_values(array_intersect($vote['options'], $message->payload['poll']['options'] ?? [])), 'voted_at' => $vote['at']],
            );

            $chatIds[$message->target->friendly_id] = true;
        }

        $this->tell($account, array_keys($chatIds));
    }

    /**
     * The vote a `pollUpdateMessage` carries, as the Evolution API decrypts
     * it (`vote.selectedOptions` with the names of the options): the
     * WhatsApp id of the poll, who voted (ME for the owner), what for and
     * when; null for any other message.
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, voter: string, options: list<string>, at: CarbonImmutable}|null
     */
    private function voteOf(array $message): ?array
    {
        $update = $message['message']['pollUpdateMessage'] ?? null;
        $id = is_array($update) ? ($update['pollCreationMessageKey']['id'] ?? null) : null;
        $voter = $this->actorOf($message);

        if (! is_string($id) || $id === '' || $voter === null) {
            return null;
        }

        $selected = $update['vote']['selectedOptions'] ?? [];

        return [
            'id' => $id,
            'voter' => $voter,
            'options' => array_values(array_filter(is_array($selected) ? $selected : [], is_string(...))),
            'at' => $this->millisecondsOr($update['senderTimestampMs'] ?? null, $message['messageTimestamp'] ?? null),
        ];
    }

    /**
     * Keeps how long the messages of each chat last when someone turns
     * disappearing messages on or off (a `protocolMessage` EPHEMERAL_SETTING).
     *
     * @param  list<array{jid: string, seconds: int}>  $timers
     */
    private function setTimers(WhatsappAccount $account, array $timers): void
    {
        foreach ($timers as $timer) {
            $chat = $account->targets()
                ->where(fn ($query) => $query->where('jid', $timer['jid'])->orWhere('lid', $timer['jid']))
                ->first();

            if ($chat !== null && $chat->ephemeral_seconds !== $timer['seconds']) {
                $chat->forceFill(['ephemeral_seconds' => $timer['seconds']])->save();
                $this->tell($account, [$chat->friendly_id]);
            }
        }
    }

    /**
     * The chat and the new timer of a `protocolMessage` EPHEMERAL_SETTING
     * (0 turns it off); null for any other.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $protocol
     * @return array{jid: string, seconds: int}|null
     */
    private function timerOf(array $message, array $protocol): ?array
    {
        $jid = $this->chatJid(is_array($message['key'] ?? null) ? $message['key'] : []);

        if (! in_array($protocol['type'] ?? null, ['EPHEMERAL_SETTING', 3], true) || $jid === null) {
            return null;
        }

        return ['jid' => $jid, 'seconds' => max(0, (int) ($protocol['ephemeralExpiration'] ?? 0))];
    }

    /**
     * The account's message with that WhatsApp id, with its chat.
     */
    private function messageOf(WhatsappAccount $account, string $externalId): ?WhatsappChatMessage
    {
        return WhatsappChatMessage::query()
            ->whereIn('whatsapp_target_id', $account->targets()->select('id'))
            ->where('external_id', $externalId)
            ->with('target')
            ->first();
    }

    /**
     * Who did what a message tells (a reaction, a vote): ME for the owner
     * of the number, else the group participant or the contact, preferring
     * the phone JID; null when the key says nobody.
     *
     * @param  array<string, mixed>  $message
     */
    private function actorOf(array $message): ?string
    {
        $key = is_array($message['key'] ?? null) ? $message['key'] : [];

        if (($key['fromMe'] ?? false) === true) {
            return WhatsappChatReaction::ME;
        }

        $actor = $key['participantAlt'] ?? $key['participant'] ?? $key['remoteJidAlt'] ?? $key['remoteJid'] ?? null;

        return is_string($actor) && $actor !== '' ? mb_substr($actor, 0, 80) : null;
    }

    /**
     * A protobuf `senderTimestampMs` / `timestampMs` (a number, a numeric
     * string or `{low, high}`), else the message's timestamp in seconds,
     * else now.
     */
    private function millisecondsOr(mixed $milliseconds, mixed $seconds = null): CarbonImmutable
    {
        if (is_array($milliseconds)) {
            $milliseconds = (int) ($milliseconds['low'] ?? 0) + (int) ($milliseconds['high'] ?? 0) * 4294967296;
        }

        if (is_numeric($milliseconds) && (int) $milliseconds > 0) {
            return CarbonImmutable::createFromTimestampMs((int) $milliseconds);
        }

        if (is_array($seconds)) {
            $seconds = $seconds['low'] ?? null;
        }

        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::createFromTimestamp((int) $seconds) : CarbonImmutable::now();
    }

    /**
     * Tells the owner's screens that these chats changed.
     *
     * @param  list<string>  $chatIds  friendly ids
     */
    private function tell(WhatsappAccount $account, array $chatIds): void
    {
        foreach ($chatIds as $chatId) {
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chatId);
        }
    }

    /**
     * Keeps who reacted to the account's messages with what (an empty emoji
     * removes the reaction; a later one replaces an earlier one), and tells
     * the screens of their chats.
     *
     * @param  list<array{id: string, reactor: string, emoji: string, at: CarbonImmutable}>  $reactions
     */
    public function react(WhatsappAccount $account, array $reactions): void
    {
        if ($reactions === []) {
            return;
        }

        $messages = WhatsappChatMessage::query()
            ->whereIn('whatsapp_target_id', $account->targets()->select('id'))
            ->whereIn('external_id', array_values(array_unique(array_column($reactions, 'id'))))
            ->with('target')
            ->get()
            ->keyBy('external_id');
        $chatIds = [];

        foreach ($reactions as $reaction) {
            $message = $messages->get($reaction['id']);

            if ($message === null) {
                continue;
            }

            $current = WhatsappChatReaction::query()->where('whatsapp_chat_message_id', $message->id)->where('reactor', $reaction['reactor'])->first();

            if ($current !== null && $current->reacted_at->greaterThan($reaction['at'])) {
                continue;
            }

            if ($reaction['emoji'] === '') {
                $current?->delete();
            } else {
                WhatsappChatReaction::query()->updateOrCreate(
                    ['whatsapp_chat_message_id' => $message->id, 'reactor' => $reaction['reactor']],
                    ['emoji' => $reaction['emoji'], 'reacted_at' => $reaction['at']],
                );
            }

            $chatIds[$message->target->friendly_id] = true;
        }

        foreach (array_keys($chatIds) as $chatId) {
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chatId);
        }
    }

    /**
     * The reaction a `reactionMessage` carries: the WhatsApp id of the
     * message reacted to, who reacted (ME for the owner of the number, else
     * the group participant or the contact), the emoji ('' removes it) and
     * when; null for any other message.
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, reactor: string, emoji: string, at: CarbonImmutable}|null
     */
    private function reactionOf(array $message): ?array
    {
        $reaction = $message['message']['reactionMessage'] ?? null;
        $id = is_array($reaction) ? ($reaction['key']['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $reactor = $this->actorOf($message);

        if ($reactor === null) {
            return null;
        }

        $at = $this->millisecondsOr($reaction['senderTimestampMs'] ?? null, $message['messageTimestamp'] ?? null);

        return ['id' => $id, 'reactor' => $reactor, 'emoji' => mb_substr((string) ($reaction['text'] ?? ''), 0, 32), 'at' => $at];
    }

    /**
     * Marks as deleted for everyone the account's messages with these
     * WhatsApp ids, and tells the screens of their chats; returns how many
     * were marked.
     *
     * @param  list<string>  $externalIds
     */
    public function revoke(WhatsappAccount $account, array $externalIds): int
    {
        if ($externalIds === []) {
            return 0;
        }

        $messages = WhatsappChatMessage::query()
            ->whereIn('whatsapp_target_id', $account->targets()->select('id'))
            ->whereIn('external_id', array_values(array_unique($externalIds)))
            ->whereNull('revoked_at')
            ->with('target')
            ->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        WhatsappChatMessage::query()->whereKey($messages->modelKeys())->update(['revoked_at' => now()]);

        foreach ($messages->pluck('target.friendly_id')->unique() as $chatId) {
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chatId);
        }

        return $messages->count();
    }

    /**
     * The WhatsApp id of the message this one deletes for everyone (a
     * `protocolMessage` REVOKE), or of itself when its updates say it was
     * deleted; null otherwise.
     *
     * @param  array<string, mixed>  $message
     */
    private function revokedId(array $message): ?string
    {
        $protocol = $message['message']['protocolMessage'] ?? null;

        if (is_array($protocol) && in_array($protocol['type'] ?? null, ['REVOKE', 0], true)) {
            $id = $protocol['key']['id'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        $updates = is_array($message['MessageUpdate'] ?? null) ? $message['MessageUpdate'] : [];
        $deleted = array_any($updates, fn (mixed $update): bool => is_array($update) && ($update['status'] ?? null) === 'DELETED');
        $id = $message['key']['id'] ?? null;

        return $deleted && is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return int how many were new
     */
    private function insert(array $rows): int
    {
        $inserted = 0;

        foreach (array_chunk(array_values($rows), self::INSERT_CHUNK) as $chunk) {
            $inserted += WhatsappChatMessage::query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * Brings the messages already kept up to the receipt they came with,
     * one query per receipt.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function raiseReceipts(WhatsappTarget $target, array $rows): void
    {
        $byReceipt = [];

        foreach ($rows as $row) {
            if ($row['from_me'] && $row['receipt'] !== null) {
                $byReceipt[$row['receipt']][] = $row['external_id'];
            }
        }

        foreach ($byReceipt as $receipt => $externalIds) {
            foreach (array_chunk($externalIds, self::INSERT_CHUNK) as $chunk) {
                WhatsappChatMessage::raiseReceipt($target->chatMessages()->getQuery(), $chunk, $receipt);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function touch(WhatsappAccount $account, WhatsappTarget $target, array $rows, int $newlyReceived, bool $answered): void
    {
        /** @var Carbon $latest */
        $latest = max(array_column($rows, 'sent_at'));

        $target->last_message_at = $target->last_message_at === null || $latest->greaterThan($target->last_message_at) ? $latest : $target->last_message_at;
        $target->unread_count = $answered ? 0 : $target->unread_count + $newlyReceived;
        $target->save();

        if ($newlyReceived > 0 || $answered) {
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $target->friendly_id);
        }
    }

    /**
     * Pushes the latest message the contact sent (one push per chat per
     * webhook) to the owner's apps, when the chat notifies messages.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function notifyReceived(WhatsappAccount $account, WhatsappTarget $target, array $rows): void
    {
        if (! $target->setRelation('account', $account)->notifiesMessages()) {
            return;
        }

        $received = array_filter($rows, fn (array $row): bool => ! $row['from_me'] && $row['revoked_at'] === null);
        usort($received, fn (array $a, array $b): int => $b['sent_at'] <=> $a['sent_at']);
        $latest = $received[0] ?? null;

        $message = $latest === null ? null : $target->chatMessages()->where('external_id', $latest['external_id'])->first();

        if ($message !== null) {
            $this->center->dispatch(new WhatsappMessageReceived($message->setRelation('target', $target)), $account->user);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{jid: string, lid: string|null, is_group: bool, from_me: bool, push_name: string|null, row: array<string, mixed>}|null
     */
    private function parse(array $message): ?array
    {
        $key = is_array($message['key'] ?? null) ? $message['key'] : [];
        $jid = $this->chatJid($key);
        $id = $key['id'] ?? null;
        $timestamp = $this->timestamp($message['messageTimestamp'] ?? null);
        $content = is_array($message['message'] ?? null) ? $this->unwrap($message['message']) : [];
        $type = $this->type($message['messageType'] ?? null, $content);

        if ($jid === null || ! is_string($id) || $id === '' || $timestamp === null || $type === null) {
            return null;
        }

        $fromMe = filter_var($key['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $pushName = is_string($message['pushName'] ?? null) && mb_trim($message['pushName']) !== '' ? mb_trim($message['pushName']) : null;
        $isGroup = str_ends_with($jid, self::GROUP_SUFFIX);
        $senderJid = $isGroup && ! $fromMe ? $this->senderJid($key) : null;

        $media = $this->mediaNode($content, $type);

        return [
            'jid' => $jid,
            'lid' => $isGroup ? null : $this->lid($key, $jid),
            'is_group' => $isGroup,
            'from_me' => $fromMe,
            'push_name' => $pushName,
            'row' => [
                'external_id' => $id,
                'from_me' => $fromMe,
                'receipt' => $fromMe ? WhatsappChatMessage::receiptOf($message) : null,
                'sender_jid' => $senderJid,
                'sender_name' => $senderJid !== null ? $pushName : null,
                'message_type' => mb_substr($type, 0, 64),
                'body' => $this->text($content),
                ...$this->context($content, $type, is_array($message['contextInfo'] ?? null) ? $message['contextInfo'] : []),
                'payload' => $this->payload($content, $type),
                'media_mime' => is_string($media['mimetype'] ?? null) ? mb_substr($media['mimetype'], 0, 127) : null,
                'media_name' => is_string($media['fileName'] ?? null) && $media['fileName'] !== '' ? mb_substr($media['fileName'], 0, 255) : null,
                'media_size' => $this->size($media['fileLength'] ?? null),
                'media_seconds' => is_numeric($media['seconds'] ?? null) && (int) $media['seconds'] > 0 ? (int) $media['seconds'] : null,
                'media_waveform' => $this->encodedBytes($media['waveform'] ?? null, maxBytes: 96),
                'media_thumbnail' => $this->encodedBytes($media['jpegThumbnail'] ?? null, maxBytes: 24 * 1024),
                'revoked_at' => null,
                'sent_at' => $timestamp,
            ],
        ];
    }

    /**
     * The JID of the chat: the group's, or the contact's preferring the
     * phone one; null for a broadcast, newsletter or status.
     *
     * @param  array<string, mixed>  $key
     */
    private function chatJid(array $key): ?string
    {
        $remoteJid = $key['remoteJid'] ?? null;

        if (is_string($remoteJid) && str_ends_with($remoteJid, self::GROUP_SUFFIX)) {
            return $remoteJid;
        }

        $candidates = array_filter(
            [$key['remoteJid'] ?? null, $key['remoteJidAlt'] ?? null, $key['senderPn'] ?? null],
            is_string(...),
        );

        foreach ($candidates as $candidate) {
            if (str_ends_with($candidate, WhatsappTarget::CONTACT_JID_SUFFIX)) {
                return $candidate;
            }
        }

        return is_string($remoteJid) && str_ends_with($remoteJid, self::LID_SUFFIX) ? $remoteJid : null;
    }

    /**
     * The `@lid` JID of a contact chat known by its phone JID, when the key
     * carries both.
     *
     * @param  array<string, mixed>  $key
     */
    private function lid(array $key, string $jid): ?string
    {
        if (str_ends_with($jid, self::LID_SUFFIX)) {
            return null;
        }

        foreach ([$key['remoteJid'] ?? null, $key['remoteJidAlt'] ?? null] as $candidate) {
            if (is_string($candidate) && str_ends_with($candidate, self::LID_SUFFIX) && mb_strlen($candidate) <= 64) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Who sent a group message: the phone JID when WhatsApp gives one,
     * otherwise the `participant` as it is.
     *
     * @param  array<string, mixed>  $key
     */
    private function senderJid(array $key): ?string
    {
        $candidates = array_values(array_filter(
            [$key['participant'] ?? null, $key['participantAlt'] ?? null, $key['participantPn'] ?? null],
            fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '',
        ));

        foreach ($candidates as $candidate) {
            if (str_ends_with($candidate, WhatsappTarget::CONTACT_JID_SUFFIX)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Names the group senders without a push name after the account's
     * contact with their JID, when there is one.
     *
     * @param  array<string, array{name: string|null, rows: list<array<string, mixed>>}>  $chats
     * @return array<string, array{name: string|null, rows: list<array<string, mixed>>}>
     */
    private function withSenderNames(WhatsappAccount $account, array $chats): array
    {
        $unnamed = [];

        foreach ($chats as $chat) {
            foreach ($chat['rows'] as $row) {
                if ($row['sender_jid'] !== null && $row['sender_name'] === null) {
                    $unnamed[$row['sender_jid']] = true;
                }
            }
        }

        if ($unnamed === []) {
            return $chats;
        }

        $names = WhatsappTarget::query()
            ->where('whatsapp_account_id', $account->id)
            ->whereIn('jid', array_keys($unnamed))
            ->whereNotNull('name')
            ->pluck('name', 'jid');

        foreach ($chats as $jid => $chat) {
            foreach ($chat['rows'] as $index => $row) {
                if ($row['sender_jid'] !== null && $row['sender_name'] === null) {
                    $chats[$jid]['rows'][$index]['sender_name'] = $names[$row['sender_jid']] ?? null;
                }
            }
        }

        return $chats;
    }

    /**
     * The content inside the ephemeral and view-once wrappers.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function unwrap(array $content): array
    {
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage'] as $wrapper) {
            $inner = $content[$wrapper]['message'] ?? null;

            if (is_array($inner)) {
                return $this->unwrap($inner);
            }
        }

        return $content;
    }

    /**
     * The message type, or null when it carries no content of its own.
     *
     * @param  array<string, mixed>  $content
     */
    private function type(mixed $messageType, array $content): ?string
    {
        $type = is_string($messageType) && $messageType !== ''
            ? $messageType
            : array_key_first(array_diff_key($content, ['messageContextInfo' => true, 'base64' => true]));

        return is_string($type) && ! in_array($type, self::IGNORED_TYPES, true) ? $type : null;
    }

    /**
     * The text, or what stands for it: a location's name, a contact's,
     * a poll's question.
     *
     * @param  array<string, mixed>  $content
     */
    private function text(array $content): ?string
    {
        $poll = $this->pollNode($content);

        $text = $content['conversation']
            ?? $content['extendedTextMessage']['text']
            ?? $content['imageMessage']['caption']
            ?? $content['videoMessage']['caption']
            ?? $content['documentMessage']['caption']
            ?? $poll['name']
            ?? $content['locationMessage']['name']
            ?? $content['contactMessage']['displayName']
            ?? $content['contactsArrayMessage']['displayName']
            ?? null;

        return is_string($text) && mb_trim($text) !== '' ? $text : null;
    }

    /**
     * What the `contextInfo` of the content says: the message it answers
     * (its id and a copy of what it said), who it mentions and whether it
     * was forwarded. The Evolution API also copies it beside the message
     * (`$outer`), the only place left for a text, whose
     * `extendedTextMessage` it turns into a plain `conversation`.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $outer
     * @return array{quoted_external_id: string|null, quoted: string|null, mentions: string|null, is_forwarded: bool}
     */
    private function context(array $content, string $type, array $outer = []): array
    {
        $inner = is_array($content[$type] ?? null) ? ($content[$type]['contextInfo'] ?? null) : null;
        $context = is_array($inner) && $inner !== [] ? $inner : $outer;

        $quotedId = is_string($context['stanzaId'] ?? null) && $context['stanzaId'] !== '' ? mb_substr($context['stanzaId'], 0, 128) : null;
        $quoted = null;

        if ($quotedId !== null) {
            $quotedContent = is_array($context['quotedMessage'] ?? null) ? $this->unwrap($context['quotedMessage']) : [];
            $quotedType = array_key_first(array_diff_key($quotedContent, ['messageContextInfo' => true]));
            $quotedBody = $this->text($quotedContent);

            $quoted = json_encode([
                'sender_jid' => is_string($context['participant'] ?? null) ? $context['participant'] : null,
                'type' => is_string($quotedType) ? mb_substr($quotedType, 0, 64) : null,
                'body' => $quotedBody !== null ? mb_substr($quotedBody, 0, 500) : null,
            ], JSON_UNESCAPED_UNICODE);
        }

        $mentions = array_values(array_filter(
            is_array($context['mentionedJid'] ?? null) ? $context['mentionedJid'] : [],
            fn (mixed $jid): bool => is_string($jid) && $jid !== '' && mb_strlen($jid) <= 80,
        ));

        return [
            'quoted_external_id' => $quotedId,
            'quoted' => $quoted,
            'mentions' => $mentions === [] ? null : json_encode(array_slice($mentions, 0, 256)),
            'is_forwarded' => ($context['isForwarded'] ?? false) === true || (is_numeric($context['forwardingScore'] ?? null) && (int) $context['forwardingScore'] > 0),
        ];
    }

    /**
     * The content of a location, contact or poll, as JSON; null for any
     * other message.
     *
     * @param  array<string, mixed>  $content
     */
    private function payload(array $content, string $type): ?string
    {
        $payload = null;
        $location = $content['locationMessage'] ?? $content['liveLocationMessage'] ?? null;
        $poll = $this->pollNode($content);

        if (is_array($location) && is_numeric($location['degreesLatitude'] ?? null) && is_numeric($location['degreesLongitude'] ?? null)) {
            $payload = ['location' => array_filter([
                'latitude' => (float) $location['degreesLatitude'],
                'longitude' => (float) $location['degreesLongitude'],
                'name' => is_string($location['name'] ?? null) ? mb_substr($location['name'], 0, 255) : null,
                'address' => is_string($location['address'] ?? null) ? mb_substr($location['address'], 0, 500) : null,
                'url' => is_string($location['url'] ?? null) ? mb_substr($location['url'], 0, 500) : null,
                'live' => $type === 'liveLocationMessage' ? true : null,
            ], fn (mixed $value): bool => $value !== null)];
        } elseif (is_array($content['contactMessage'] ?? null) || is_array($content['contactsArrayMessage'] ?? null)) {
            $cards = is_array($content['contactMessage'] ?? null)
                ? [$content['contactMessage']]
                : (is_array($content['contactsArrayMessage']['contacts'] ?? null) ? $content['contactsArrayMessage']['contacts'] : []);

            $payload = ['contacts' => array_values(array_map(fn (array $card): array => [
                'name' => is_string($card['displayName'] ?? null) ? mb_substr($card['displayName'], 0, 255) : null,
                'numbers' => WhatsappChatMessage::numbersInVcard(is_string($card['vcard'] ?? null) ? $card['vcard'] : ''),
            ], array_slice(array_filter($cards, is_array(...)), 0, 50)))];
        } elseif ($poll !== null) {
            $options = array_values(array_filter(array_map(
                fn (mixed $option): ?string => is_array($option) && is_string($option['optionName'] ?? null) ? mb_substr($option['optionName'], 0, 255) : null,
                is_array($poll['options'] ?? null) ? $poll['options'] : [],
            ), is_string(...)));

            $payload = ['poll' => [
                'name' => is_string($poll['name'] ?? null) ? mb_substr($poll['name'], 0, 255) : '',
                'options' => array_slice($options, 0, 12),
                'selectable_count' => is_numeric($poll['selectableOptionsCount'] ?? null) ? (int) $poll['selectableOptionsCount'] : 0,
            ]];
        }

        return $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The poll of the content, in any of WhatsApp's versions of one.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null
     */
    private function pollNode(array $content): ?array
    {
        foreach (WhatsappChatMessage::POLL_TYPES as $type) {
            if (is_array($content[$type] ?? null)) {
                return $content[$type];
            }
        }

        return null;
    }

    /**
     * The part of the content that describes the file: the one of the
     * message type, or the first media found (a document wrapped with its
     * caption is reported as `documentWithCaptionMessage`).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function mediaNode(array $content, string $type): array
    {
        foreach ([$type, ...array_keys(WhatsappChatMessage::MEDIA_KINDS)] as $key) {
            if (is_array($content[$key] ?? null)) {
                return $content[$key];
            }
        }

        return [];
    }

    /**
     * A protobuf `fileLength`: a number, a numeric string or `{low, high}`.
     */
    private function size(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = (int) ($value['low'] ?? 0) + (int) ($value['high'] ?? 0) * 4294967296;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Binary WhatsApp sends as base64, or as a serialized byte array (a
     * list, or `{"0": …, "1": …}`), back as base64; null when empty, not
     * binary or above `$maxBytes`.
     */
    private function encodedBytes(mixed $value, int $maxBytes): ?string
    {
        if (is_array($value)) {
            $bytes = array_values(array_filter($value, fn (mixed $byte): bool => is_int($byte) && $byte >= 0 && $byte <= 255));
            $value = $bytes !== [] && count($bytes) === count($value) ? base64_encode(pack('C*', ...$bytes)) : null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        return $decoded !== false && $decoded !== '' && strlen($decoded) <= $maxBytes ? base64_encode($decoded) : null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (is_array($value)) {
            $value = $value['low'] ?? null;
        }

        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }

    private function targetFor(WhatsappAccount $account, string $jid, ?string $name, ?string $lid): WhatsappTarget
    {
        $target = WhatsappTarget::query()->firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'jid' => $jid],
            [
                'whatsapp_target_type_id' => WhatsappTargetType::idFor(str_ends_with($jid, self::GROUP_SUFFIX) ? WhatsappTargetType::GROUP : WhatsappTargetType::CONTACT),
                'name' => $name,
                'is_synced' => true,
            ],
        );

        if ($target->name === null && $name !== null) {
            $target->update(['name' => $name]);
        }

        if ($lid !== null && $target->lid !== $lid) {
            $target->update(['lid' => $lid]);
        }

        return $target;
    }
}
