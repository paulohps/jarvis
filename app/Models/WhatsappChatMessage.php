<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use App\Support\Whatsapp\MessageFormat;
use Database\Factories\WhatsappChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * A message of a chat of an account (one-to-one or group), received or sent
 * by the number (any device), as the Evolution API reported it: live
 * (`messages.upsert`) or from the history WhatsApp hands over when the
 * number is linked (`messages.set`). Read by the WhatsApp screen and the
 * reply keyboard of the apps, which drafts an answer in the owner's style.
 *
 * In a group, `sender_jid` and `sender_name` say who sent a received
 * message; both are null in a one-to-one chat and for what the number sent.
 *
 * `body` is the text (or the caption of a media); null for a media without
 * caption, an audio or anything else without text, `message_type` telling
 * which (`conversation`, `audioMessage`, `imageMessage`…).
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $whatsapp_target_id
 * @property string $external_id
 * @property bool $from_me
 * @property string|null $sender_jid
 * @property string|null $sender_name
 * @property string $message_type
 * @property string|null $body
 * @property string|null $quoted_external_id the WhatsApp id of the message it answers
 * @property array{sender_jid: string|null, from_me: bool, type: string|null, body: string|null}|null $quoted what the message it answers said, as it came
 * @property list<string>|null $mentions the JIDs it mentions
 * @property array{location?: array<string, mixed>, contacts?: list<array<string, mixed>>, poll?: array{name: string, options: list<string>, selectable_count: int}}|null $payload
 * @property bool $is_forwarded
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property int|null $media_size
 * @property string|null $media_path
 * @property int|null $receipt
 * @property Carbon|null $media_accessed_at
 * @property int|null $media_seconds
 * @property string|null $media_waveform
 * @property string|null $media_thumbnail
 * @property Carbon|null $revoked_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $pinned_at
 * @property Carbon|null $pinned_until
 * @property Carbon|null $starred_at
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappTarget $target
 * @property-read Collection<int, WhatsappChatReaction> $reactions
 * @property-read Collection<int, WhatsappPollVote> $pollVotes
 * @property-read Collection<int, WhatsappChatReceipt> $receipts
 * @property-read WhatsappChatMessage|null $quotedMessage the message it answers, when kept (withContext())
 */
#[Fillable(['whatsapp_target_id', 'external_id', 'from_me', 'receipt', 'sender_jid', 'sender_name', 'message_type', 'body', 'media_mime', 'media_name', 'media_size', 'media_seconds', 'media_waveform', 'media_thumbnail', 'media_path', 'media_accessed_at', 'revoked_at', 'sent_at', 'quoted_external_id', 'quoted', 'mentions', 'payload', 'is_forwarded', 'edited_at', 'pinned_at', 'pinned_until', 'starred_at'])]
class WhatsappChatMessage extends Model
{
    /** @use HasFactory<WhatsappChatMessageFactory> */
    use HasFactory, HasFriendlyId;

    /**
     * The names of who it mentions and of who wrote the message it answers,
     * by JID (withContext()); not kept.
     *
     * @var array<string, string>
     */
    public array $contextNames = [];

    /**
     * Who sent it, for a received group message: the name it showed,
     * otherwise its number (or JID); null otherwise.
     */
    public function senderLabel(): ?string
    {
        if ($this->sender_jid === null) {
            return $this->sender_name;
        }

        return $this->sender_name ?? (str_ends_with($this->sender_jid, WhatsappTarget::CONTACT_JID_SUFFIX)
            ? '+'.strstr($this->sender_jid, '@', true)
            : $this->sender_jid);
    }

    /**
     * The message types that carry a file, and how the screen shows each.
     *
     * @var array<string, string>
     */
    public const array MEDIA_KINDS = [
        'imageMessage' => 'image',
        'stickerMessage' => 'image',
        'videoMessage' => 'video',
        'ptvMessage' => 'video',
        'audioMessage' => 'audio',
        'documentMessage' => 'document',
        'documentWithCaptionMessage' => 'document',
    ];

    /**
     * The message types of a poll, by WhatsApp's version of it.
     *
     * @var list<string>
     */
    public const array POLL_TYPES = ['pollCreationMessage', 'pollCreationMessageV2', 'pollCreationMessageV3'];

    /**
     * The phone numbers (E.164) a vCard lists, from its `waid` or its `TEL`
     * lines.
     *
     * @return list<string>
     */
    public static function numbersInVcard(string $vcard): array
    {
        $numbers = [];

        foreach (preg_split('/\r\n|\r|\n/', $vcard) ?: [] as $line) {
            if (! preg_match('/^(?:item\d+\.)?TEL\b/i', $line)) {
                continue;
            }

            $digits = preg_match('/waid=(\d+)/i', $line, $match) ? $match[1] : preg_replace('/\D/', '', (string) substr((string) strrchr($line, ':'), 1));

            if (is_string($digits) && $digits !== '' && strlen($digits) <= 15) {
                $numbers[] = '+'.$digits;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * How far a message the number sent got, as WhatsApp's ticks show it:
     * failed, waiting (clock), sent (one tick), delivered (two ticks), read
     * or played (two blue ticks). Only goes up.
     */
    public const int RECEIPT_FAILED = 0;

    public const int RECEIPT_PENDING = 1;

    public const int RECEIPT_SENT = 2;

    public const int RECEIPT_DELIVERED = 3;

    public const int RECEIPT_READ = 4;

    public const int RECEIPT_PLAYED = 5;

    /**
     * The Evolution API's names for each receipt (Baileys' `WAMessageStatus`).
     *
     * @var array<string, int>
     */
    public const array RECEIPTS = [
        'ERROR' => self::RECEIPT_FAILED,
        'PENDING' => self::RECEIPT_PENDING,
        'SERVER_ACK' => self::RECEIPT_SENT,
        'DELIVERY_ACK' => self::RECEIPT_DELIVERED,
        'READ' => self::RECEIPT_READ,
        'PLAYED' => self::RECEIPT_PLAYED,
    ];

    /**
     * The receipt of an Evolution API status, by name (`DELIVERY_ACK`) or
     * by Baileys' number; null for an unknown one.
     */
    public static function receiptFrom(mixed $status): ?int
    {
        if (is_int($status) || (is_string($status) && ctype_digit($status))) {
            return in_array((int) $status, self::RECEIPTS, true) ? (int) $status : null;
        }

        return is_string($status) ? self::RECEIPTS[mb_strtoupper($status)] ?? null : null;
    }

    /**
     * The furthest receipt of a message as the Evolution API keeps it: its
     * `status`, or any of its updates (`MessageUpdate`), which do not come
     * in order.
     *
     * @param  array<string, mixed>  $message
     */
    public static function receiptOf(array $message): ?int
    {
        $updates = is_array($message['MessageUpdate'] ?? null) ? $message['MessageUpdate'] : [];
        $receipts = array_filter(
            [self::receiptFrom($message['status'] ?? null), ...array_map(fn (mixed $update): ?int => is_array($update) ? self::receiptFrom($update['status'] ?? null) : null, $updates)],
            is_int(...),
        );

        return $receipts === [] ? null : max($receipts);
    }

    /**
     * Raises to `$receipt` the messages the number sent among `$query`
     * with those WhatsApp ids, leaving the ones already further; returns
     * how many changed.
     *
     * @param  Builder<self>  $query
     * @param  list<string>  $externalIds
     */
    public static function raiseReceipt(Builder $query, array $externalIds, int $receipt): int
    {
        return $query->whereIn('external_id', $externalIds)
            ->where('from_me', true)
            ->where(fn (Builder $query) => $query->whereNull('receipt')->orWhere('receipt', '<', $receipt))
            ->update(['receipt' => $receipt]);
    }

    /**
     * Files above this size are not fetched: they stay on the phone.
     */
    public const int MAX_MEDIA_BYTES = 32 * 1024 * 1024;

    /**
     * How the screen shows the file (`image`, `video`, `audio`,
     * `document`), or null for a message without one.
     */
    public function mediaKind(): ?string
    {
        return self::MEDIA_KINDS[$this->message_type] ?? null;
    }

    /**
     * Whether the user can download the file: a media already kept, or one
     * not above MAX_MEDIA_BYTES.
     */
    public function canDownloadMedia(): bool
    {
        return $this->mediaKind() !== null
            && ($this->media_path !== null || $this->media_size === null || $this->media_size <= self::MAX_MEDIA_BYTES);
    }

    /**
     * Whether the sender deleted it for everyone on WhatsApp; Jarvis keeps
     * showing it, marked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * The heights (0 to 100) of `$count` bars of an audio's waveform: the
     * one WhatsApp sent with a voice note, or, without one, steady made-up
     * bars, the same for the same message every time.
     *
     * @return list<int>
     */
    public function waveformBars(int $count = 40): array
    {
        $samples = $this->media_waveform !== null ? array_values(unpack('C*', (string) base64_decode($this->media_waveform, true)) ?: []) : [];

        if ($samples === []) {
            mt_srand(crc32($this->external_id));
            $bars = array_map(fn (): int => mt_rand(25, 85), range(1, $count));
            mt_srand();

            return $bars;
        }

        $chunks = array_chunk($samples, max(1, (int) ceil(count($samples) / $count)));
        $peak = max(1, max($samples));

        return array_map(fn (array $chunk): int => max(8, (int) round(max($chunk) / $peak * 100)), $chunks);
    }

    /**
     * The tiny preview WhatsApp sends with an image or video, as a `data:`
     * URI, or null without one.
     */
    public function thumbnailUri(): ?string
    {
        return $this->media_thumbnail !== null ? 'data:image/jpeg;base64,'.$this->media_thumbnail : null;
    }

    public static function idPrefix(): string
    {
        return 'wchm';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'sent_at' => 'datetime',
            'media_size' => 'integer',
            'receipt' => 'integer',
            'media_accessed_at' => 'datetime',
            'media_seconds' => 'integer',
            'revoked_at' => 'datetime',
            'quoted' => 'array',
            'mentions' => 'array',
            'payload' => 'array',
            'is_forwarded' => 'boolean',
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
            'pinned_until' => 'datetime',
            'starred_at' => 'datetime',
        ];
    }

    /**
     * The text, or what WhatsApp shows for the kind of message (“Photo”,
     * “Location”…).
     */
    public function bodyOrPlaceholder(): string
    {
        return filled($this->body) ? (string) $this->body : $this->summary()['label'];
    }

    /**
     * How the chat list sums the message up, as WhatsApp does: the icon of
     * its kind (a Heroicon, null for a text) and its text, else the name of
     * its kind (“Photo”, “Voice message 0:12”, the file's name…). A message
     * Jarvis cannot show says so and points to the phone.
     *
     * @return array{icon: string|null, label: string, text: string|null}
     */
    public function summary(): array
    {
        $type = (string) $this->message_type;
        $seconds = $this->media_seconds !== null && $this->media_seconds > 0
            ? sprintf('%d:%02d', intdiv($this->media_seconds, 60), $this->media_seconds % 60)
            : null;

        [$icon, $label] = match (true) {
            in_array($type, ['conversation', 'extendedTextMessage'], true) => [null, __('Message')],
            $type === 'imageMessage' => ['photo', __('Photo')],
            $type === 'stickerMessage' || $type === 'lottieStickerMessage' => ['face-smile', __('Sticker')],
            $type === 'videoMessage' => ['video-camera', __('Video')],
            $type === 'ptvMessage' => ['video-camera', __('Video message')],
            $type === 'audioMessage' => ['microphone', trim(($this->media_waveform !== null ? __('Voice message') : __('Audio')).' '.$seconds)],
            in_array($type, ['documentMessage', 'documentWithCaptionMessage'], true) => ['document', $this->media_name ?? __('Document')],
            $type === 'locationMessage' => ['map-pin', __('Location')],
            $type === 'liveLocationMessage' => ['map-pin', __('Live location')],
            in_array($type, ['contactMessage', 'contactsArrayMessage'], true) => ['user', __('Contact')],
            in_array($type, self::POLL_TYPES, true) => ['chart-bar', __('Poll')],
            $type === 'eventMessage' => ['calendar', __('Event')],
            $type === 'groupInviteMessage' => ['user-group', __('Group invite')],
            in_array($type, ['callLogMessage', 'bcallMessage', 'call'], true) => ['phone', __('Call')],
            str_starts_with($type, 'viewOnceMessage') => ['eye', __('You received a view once message. For more privacy, it can only be opened on the phone.')],
            default => ['device-phone-mobile', __('This message is not supported here. See it on the phone.')],
        };

        return ['icon' => $icon, 'label' => $label, 'text' => filled($this->body) ? (string) $this->body : null];
    }

    /**
     * The contact of the chat.
     *
     * @return BelongsTo<WhatsappTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(WhatsappTarget::class, 'whatsapp_target_id');
    }

    /**
     * Who reacted to it, with what, oldest first.
     *
     * @return HasMany<WhatsappChatReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(WhatsappChatReaction::class)->orderBy('reacted_at')->orderBy('id');
    }

    /**
     * The reactions grouped by emoji, the most used first: each with how
     * many and whether the owner is one of them.
     *
     * @return list<array{emoji: string, count: int, mine: bool}>
     */
    public function reactionSummary(): array
    {
        return $this->reactions
            ->groupBy('emoji')
            ->map(fn (Collection $reactions, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $reactions->count(),
                'mine' => $reactions->contains(fn (WhatsappChatReaction $reaction): bool => $reaction->isMine()),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * The votes of its poll, oldest first.
     *
     * @return HasMany<WhatsappPollVote, $this>
     */
    public function pollVotes(): HasMany
    {
        return $this->hasMany(WhatsappPollVote::class)->orderBy('voted_at')->orderBy('id');
    }

    /**
     * When it reached each person and when they read or played it.
     *
     * @return HasMany<WhatsappChatReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(WhatsappChatReceipt::class)->orderBy('id');
    }

    /**
     * Whether it is a poll (any of WhatsApp's versions of one).
     */
    public function isPoll(): bool
    {
        return isset($this->payload['poll']);
    }

    /**
     * Each option of its poll with how many voted for it and whether the
     * owner did; empty for a message that is no poll.
     *
     * @return list<array{name: string, votes: int, mine: bool}>
     */
    public function pollTally(): array
    {
        if (! $this->isPoll()) {
            return [];
        }

        return array_map(fn (string $option): array => [
            'name' => $option,
            'votes' => $this->pollVotes->filter(fn (WhatsappPollVote $vote): bool => in_array($option, $vote->options, true))->count(),
            'mine' => $this->pollVotes->contains(fn (WhatsappPollVote $vote): bool => $vote->isMine() && in_array($option, $vote->options, true)),
        ], array_values($this->payload['poll']['options'] ?? []));
    }

    /**
     * Whether it is pinned in the chat now (a pin lasts until `pinned_until`).
     */
    public function isPinned(): bool
    {
        return $this->pinned_at !== null && ($this->pinned_until === null || $this->pinned_until->isFuture());
    }

    /**
     * Whether the owner can still edit it on WhatsApp: a text, or the
     * caption of an image or video, the number sent, not deleted, within
     * EDIT_MINUTES (the Evolution API edits nothing else).
     */
    public function canEdit(): bool
    {
        return $this->from_me
            && ! $this->isRevoked()
            && (in_array($this->message_type, ['conversation', 'extendedTextMessage'], true) || (in_array($this->message_type, ['imageMessage', 'videoMessage'], true) && filled($this->body)))
            && $this->sent_at->greaterThan(now()->subMinutes(self::EDIT_MINUTES));
    }

    /**
     * WhatsApp lets the sender edit a message for 15 minutes.
     */
    public const int EDIT_MINUTES = 15;

    /**
     * WhatsApp lets the sender delete a message for everyone for about two
     * and a half days.
     */
    public const int DELETE_FOR_EVERYONE_HOURS = 60;

    /**
     * Whether the owner can still delete it for everyone: one the number
     * sent, not deleted yet, within DELETE_FOR_EVERYONE_HOURS.
     */
    public function canDeleteForEveryone(): bool
    {
        return $this->from_me
            && ! $this->isRevoked()
            && $this->sent_at->greaterThan(now()->subHours(self::DELETE_FOR_EVERYONE_HOURS));
    }

    /**
     * The message as a reply quotes it when sending: its key and, for a
     * text, what it said (so the Evolution API need not keep it; for
     * anything else it looks the message up by its id).
     *
     * @return array{key: array<string, mixed>, message?: array{conversation: string}}
     */
    public function quoteForSending(): array
    {
        $quote = ['key' => $this->whatsappKey()];

        if (in_array($this->message_type, ['conversation', 'extendedTextMessage'], true) && $this->body !== null) {
            $quote['message'] = ['conversation' => $this->body];
        }

        return $quote;
    }

    /**
     * Gives each message of a chat what it shows beyond itself: the
     * message it answers when it is kept (`quotedMessage`, null otherwise)
     * and the names of who it mentions (`contextNames`, by JID), with one
     * query each.
     *
     * @param  Collection<int, self>  $messages
     * @return Collection<int, self>
     */
    public static function withContext(Collection $messages, WhatsappTarget $chat): Collection
    {
        $quotedIds = $messages->pluck('quoted_external_id')->filter()->unique()->values()->all();
        $quoted = $quotedIds === [] ? collect() : $chat->chatMessages()->whereIn('external_id', $quotedIds)->get()->keyBy('external_id');

        $jids = $messages->flatMap(fn (self $message): array => [...($message->mentions ?? []), $message->quoted['sender_jid'] ?? null])->filter()->unique()->values()->all();
        $names = $jids === [] ? [] : WhatsappTarget::query()
            ->where('whatsapp_account_id', $chat->whatsapp_account_id)
            ->where(fn (Builder $query) => $query->whereIn('jid', $jids)->orWhereIn('lid', $jids))
            ->get()
            ->flatMap(fn (WhatsappTarget $contact): array => array_filter([$contact->jid => $contact->displayName(), $contact->lid ?? '' => $contact->displayName()], fn (string $name, string $jid): bool => $jid !== '', ARRAY_FILTER_USE_BOTH))
            ->all();

        return $messages->each(function (self $message) use ($quoted, $names, $chat): void {
            $message->setRelation('target', $chat);
            $message->setRelation('quotedMessage', $message->quoted_external_id !== null ? $quoted->get($message->quoted_external_id) : null);
            $message->contextNames = array_intersect_key($names, array_flip(array_filter([...($message->mentions ?? []), $message->quoted['sender_jid'] ?? null])));
        });
    }

    /**
     * The text as the chat shows it, formatted as WhatsApp formats it, with
     * each `@number` it mentions as the person's name (MessageFormat).
     */
    public function bodyHtml(): HtmlString
    {
        $mentions = [];

        foreach ($this->mentions ?? [] as $jid) {
            $user = (string) strstr($jid, '@', true);
            $mentions['@'.$user] = $this->contextNames[$jid] ?? (str_ends_with($jid, WhatsappTarget::CONTACT_JID_SUFFIX) ? '+'.$user : $user);
        }

        return MessageFormat::toHtml((string) $this->body, $mentions);
    }

    /**
     * The text with each `@number` it mentions written as `@name`.
     */
    public function bodyWithMentions(): ?string
    {
        if ($this->body === null || empty($this->mentions)) {
            return $this->body;
        }

        $replacements = [];

        foreach ($this->mentions as $jid) {
            $user = (string) strstr($jid, '@', true);
            $replacements['@'.$user] = '@'.($this->contextNames[$jid] ?? (str_ends_with($jid, WhatsappTarget::CONTACT_JID_SUFFIX) ? '+'.$user : $user));
        }

        return strtr($this->body, $replacements);
    }

    /**
     * Who wrote the message it answers, as the screen shows it: "You" for
     * the owner, otherwise the contact's name or number.
     */
    public function quotedSenderLabel(): ?string
    {
        if ($this->quoted === null) {
            return null;
        }

        if ($this->quotedMessage !== null) {
            return $this->quotedMessage->from_me ? __('You') : ($this->quotedMessage->senderLabel() ?? $this->target->displayName());
        }

        $jid = $this->quoted['sender_jid'] ?? null;

        if ($jid === null) {
            return $this->target->displayName();
        }

        return $this->contextNames[$jid] ?? (str_ends_with($jid, WhatsappTarget::CONTACT_JID_SUFFIX) ? '+'.strstr($jid, '@', true) : $this->target->displayName());
    }

    /**
     * Its WhatsApp key, to react to, edit, delete, pin or vote on it:
     * `remoteJid`, `fromMe`, `id` and, for what a group member sent, the
     * `participant`.
     *
     * @return array{remoteJid: string, fromMe: bool, id: string, participant?: string}
     */
    public function whatsappKey(): array
    {
        $chat = $this->target;

        return array_filter([
            'remoteJid' => $chat->jid,
            'fromMe' => $this->from_me,
            'id' => $this->external_id,
            'participant' => $chat->isGroup() && ! $this->from_me ? $this->sender_jid : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
