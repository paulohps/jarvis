<?php

namespace App\Models;

use App\Models\Concerns\HasAiTriage;
use App\Models\Concerns\HasFriendlyId;
use App\Observers\WhatsappTargetObserver;
use Database\Factories\WhatsappTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A contact or group an account can send to, identified by its JID. Synced
 * from the Evolution API, or typed manually (`is_synced` false).
 *
 * A target with a chat (a message either way, or pinned) is also an entry
 * of the chat screen (`conversation`, a ChatConversation): the target keeps
 * the state of the chat — the phone, the WhatsApp screen, the apps and the
 * webhooks all write here — and WhatsappTargetObserver copies it onto the
 * entry at each save; the chat layer writes back through
 * WhatsappChatProvider::entryChanged().
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $whatsapp_account_id
 * @property int $whatsapp_target_type_id
 * @property string $jid
 * @property string|null $lid the `@lid` JID WhatsApp also knows the contact by
 * @property string|null $name
 * @property string|null $nickname the user's own, over the name WhatsApp gives
 * @property bool $is_synced
 * @property Carbon|null $last_message_at
 * @property int $unread_count
 * @property Carbon|null $pinned_at
 * @property Carbon|null $archived_at
 * @property bool $marked_unread marked unread by hand, on the phone or in Jarvis
 * @property Carbon|null $muted_until notifications stay silent until then (far ahead: always)
 * @property Carbon|null $blocked_at when the owner blocked the contact
 * @property int|null $ephemeral_seconds how long its messages last (disappearing messages); null when they stay
 * @property string|null $description a group's description
 * @property int|null $priority the user's own, 1 the highest; the AI never sets it
 * @property int|null $ai_urgency how urgent the AI found the chat (AI_URGENCIES), apart from `priority`
 * @property string|null $ai_summary what the chat wants from the owner, as the AI read it
 * @property Carbon|null $ai_triaged_at
 * @property Carbon|null $ai_triaged_through the last message the AI read; a newer one makes it out of date
 * @property Carbon|null $ai_triage_requested_at the AI was asked and did not answer yet; all the `ai_*` go once the chat is read
 * @property string|null $picture_url
 * @property Carbon|null $picture_checked_at
 * @property bool|null $sends_read_receipts null follows the account
 * @property bool|null $sends_typing null follows the account
 * @property bool|null $notifies_messages null follows the account
 * @property Carbon|null $read_up_to how far WhatsApp was told the chat was read
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappAccount $account
 * @property-read WhatsappTargetType $type
 * @property-read WhatsappChatMessage|null $lastChatMessage
 * @property-read WhatsappChatMessage|null $lastReceivedChatMessage
 * @property-read ChatConversation|null $conversation
 */
#[ObservedBy([WhatsappTargetObserver::class])]
#[Fillable(['whatsapp_account_id', 'whatsapp_target_type_id', 'jid', 'lid', 'name', 'nickname', 'is_synced', 'last_message_at', 'unread_count', 'pinned_at', 'priority', 'picture_url', 'picture_checked_at', 'sends_read_receipts', 'sends_typing', 'notifies_messages', 'read_up_to', 'archived_at', 'marked_unread', 'muted_until', 'blocked_at', 'ephemeral_seconds', 'description', 'ai_urgency', 'ai_summary', 'ai_triaged_at', 'ai_triaged_through', 'ai_triage_requested_at'])]
class WhatsappTarget extends Model
{
    /** @use HasFactory<WhatsappTargetFactory> */
    use HasAiTriage, HasFactory, HasFriendlyId;

    /**
     * How the JID of a person ends; a group's ends with `@g.us`.
     */
    public const string CONTACT_JID_SUFFIX = '@s.whatsapp.net';

    /**
     * The priorities of a chat on the WhatsApp screen, 1 the highest.
     */
    public const int HIGHEST_PRIORITY = 1;

    public const int LOWEST_PRIORITY = 5;

    /**
     * The colors a priority may take (the iOS system colors), and the one
     * each priority takes until the user picks another
     * (User::whatsappPriorityColors()).
     *
     * @var array<string, string>
     */
    public const array PRIORITY_COLORS = [
        'red' => '#ff3b30',
        'orange' => '#ff9500',
        'yellow' => '#ffcc00',
        'green' => '#34c759',
        'blue' => '#007aff',
        'pink' => '#ff2d55',
        'brown' => '#a2845e',
        'gray' => '#8e8e93',
    ];

    /**
     * @var array<int, string>
     */
    /** How many unanswered messages the AI answers at most when none is picked. */
    public const int LATEST_RECEIVED = 10;

    public const array DEFAULT_PRIORITY_COLORS = [1 => 'red', 2 => 'orange', 3 => 'yellow', 4 => 'green', 5 => 'blue'];

    public static function idPrefix(): string
    {
        return 'wtgt';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_synced' => 'boolean',
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
            'pinned_at' => 'datetime',
            'priority' => 'integer',
            'picture_checked_at' => 'datetime',
            'sends_read_receipts' => 'boolean',
            'sends_typing' => 'boolean',
            'notifies_messages' => 'boolean',
            'read_up_to' => 'datetime',
            'archived_at' => 'datetime',
            'marked_unread' => 'boolean',
            'muted_until' => 'datetime',
            'blocked_at' => 'datetime',
            'ephemeral_seconds' => 'integer',
        ];
    }

    /**
     * The JID of a contact typed as an E.164 number (`+5511999990000`).
     */
    public static function contactJidFor(string $e164Number): string
    {
        return ltrim($e164Number, '+').self::CONTACT_JID_SUFFIX;
    }

    /**
     * The account's target for a number typed in E.164: the contact already
     * known (synced or typed before), or a new one with `is_synced` false and
     * no name.
     */
    public static function forNumber(WhatsappAccount $account, string $e164Number): self
    {
        return self::query()->firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'jid' => self::contactJidFor($e164Number)],
            ['whatsapp_target_type_id' => WhatsappTargetType::idFor(WhatsappTargetType::CONTACT), 'name' => null, 'is_synced' => false],
        );
    }

    /**
     * The number of a contact in E.164, or null for a group.
     */
    public function phoneNumber(): ?string
    {
        return str_ends_with($this->jid, self::CONTACT_JID_SUFFIX)
            ? '+'.Str::before($this->jid, self::CONTACT_JID_SUFFIX)
            : null;
    }

    /**
     * The name to show: the user's nickname, otherwise the synced name,
     * otherwise the number (or the JID).
     */
    public function displayName(): string
    {
        return match (true) {
            filled($this->nickname) => (string) $this->nickname,
            filled($this->name) => (string) $this->name,
            default => $this->phoneNumber() ?? $this->jid,
        };
    }

    /**
     * Whether the target is a group: its JID ends with `@g.us`.
     */
    public function isGroup(): bool
    {
        return str_ends_with($this->jid, '@g.us');
    }

    /**
     * Whether the target is listed as a chat: a message either way, or
     * pinned. The chat screens (WhatsApp's and the unified one) show these.
     */
    public function hasChat(): bool
    {
        return $this->last_message_at !== null || $this->pinned_at !== null;
    }

    /**
     * The kind of the entry on the chat screen.
     */
    public function chatKind(): string
    {
        return $this->isGroup() ? ChatConversation::KIND_GROUP : ChatConversation::KIND_DIRECT;
    }

    /**
     * The name WhatsApp gives, before the user's nickname: the synced name,
     * else the number (or the JID).
     */
    public function providerName(): string
    {
        return filled($this->name) ? (string) $this->name : ($this->phoneNumber() ?? $this->jid);
    }

    /**
     * Whether opening the chat tells WhatsApp it was read: the chat's own
     * choice, else its number's.
     */
    public function sendsReadReceipts(): bool
    {
        return $this->sends_read_receipts ?? $this->account->sends_read_receipts;
    }

    /**
     * Whether typing in the chat shows "typing…" on WhatsApp: the chat's
     * own choice, else its number's.
     */
    public function sendsTyping(): bool
    {
        return $this->sends_typing ?? $this->account->sends_typing;
    }

    /**
     * Whether what the contact (or a group member) sends reaches the apps
     * by push: never while muted, else its own setting, else its number's.
     */
    public function notifiesMessages(): bool
    {
        return ! $this->isMuted() && ($this->notifies_messages ?? $this->account->notifies_messages ?? true);
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether its notifications are silenced now.
     */
    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Whether the chat list shows it as unread: messages not read, or
     * marked unread by hand.
     */
    public function isUnread(): bool
    {
        return $this->unread_count > 0 || $this->marked_unread;
    }

    /**
     * The cache key of the members of a group (ListWhatsappGroupParticipants),
     * forgotten when WhatsApp says they changed.
     */
    public function participantsCacheKey(): string
    {
        return 'whatsapp:group-participants:'.$this->id;
    }

    /**
     * The timers of disappearing messages WhatsApp offers, in seconds
     * (0: off), with their labels.
     *
     * @var array<int, string>
     */
    public const array EPHEMERAL_TIMERS = [0 => 'Off', 86400 => '24 hours', 604800 => '7 days', 7776000 => '90 days'];

    /**
     * A mute "always", as WhatsApp keeps it: far ahead.
     */
    public const string MUTED_FOREVER = '9999-12-31 00:00:00';

    /**
     * The hex color of the chat's priority, as the owner set it for that
     * priority (`$colors`, User::whatsappPriorityColors(), given to spare a
     * query per chat), or null without a priority.
     *
     * @param  array<int, string>|null  $colors
     */
    public function priorityHex(?array $colors = null): ?string
    {
        if ($this->priority === null) {
            return null;
        }

        $colors ??= $this->account->user->whatsappPriorityColors();

        return self::PRIORITY_COLORS[$colors[$this->priority] ?? 'gray'] ?? self::PRIORITY_COLORS['gray'];
    }

    /**
     * @return BelongsTo<WhatsappAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    /**
     * @return BelongsTo<WhatsappTargetType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(WhatsappTargetType::class, 'whatsapp_target_type_id');
    }

    /**
     * @return BelongsToMany<WhatsappMessage, $this>
     */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappMessage::class)->withTimestamps();
    }

    /**
     * The user's tags on the chat.
     *
     * @return BelongsToMany<WhatsappTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappTag::class)->withTimestamps()->orderBy('name');
    }

    /**
     * The reminders created from the chat on the WhatsApp screen; the pivot
     * has the message it was created from, if any.
     *
     * @return BelongsToMany<Reminder, $this>
     */
    public function reminders(): BelongsToMany
    {
        return $this->belongsToMany(Reminder::class, 'whatsapp_chat_reminders')
            ->withPivot('whatsapp_chat_message_id')
            ->withTimestamps();
    }

    /**
     * Its entry on the unified chat screen, kept by WhatsappTargetObserver.
     *
     * @return MorphOne<ChatConversation, $this>
     */
    public function conversation(): MorphOne
    {
        return $this->morphOne(ChatConversation::class, 'conversable');
    }

    /**
     * The messages of the one-to-one chat with the contact.
     *
     * @return HasMany<WhatsappChatMessage, $this>
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(WhatsappChatMessage::class);
    }

    /**
     * The messages of the chat whose text has all the words searched for,
     * newest first.
     *
     * @return HasMany<WhatsappChatMessage, $this>
     */
    public function searchMessages(string $search): HasMany
    {
        $words = array_slice(array_filter(preg_split('/\s+/', mb_trim($search)) ?: [], fn (string $word): bool => $word !== ''), 0, 8);

        return $this->chatMessages()
            ->whereNotNull('body')
            ->where(function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    $query->whereLike('body', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->orderByDesc('sent_at')
            ->orderByDesc('id');
    }

    /**
     * What the chat's media gallery lists, newest first: its photos and
     * videos (`media`), its documents (`documents`) or the messages with a
     * link (`links`).
     *
     * @return HasMany<WhatsappChatMessage, $this>
     */
    public function galleryMessages(string $kind): HasMany
    {
        $types = fn (string $kind): array => array_keys(array_filter(WhatsappChatMessage::MEDIA_KINDS, fn (string $shown, string $type): bool => $shown === $kind && $type !== 'stickerMessage', ARRAY_FILTER_USE_BOTH));

        return $this->chatMessages()
            ->whereNull('revoked_at')
            ->when($kind === self::GALLERY_MEDIA, fn (Builder $query) => $query->whereIn('message_type', [...$types('image'), ...$types('video')]))
            ->when($kind === self::GALLERY_DOCUMENTS, fn (Builder $query) => $query->whereIn('message_type', $types('document')))
            ->when($kind === self::GALLERY_LINKS, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('body', '%http://%')->orWhereLike('body', '%https://%')))
            ->orderByDesc('sent_at')
            ->orderByDesc('id');
    }

    public const string GALLERY_MEDIA = 'media';

    public const string GALLERY_DOCUMENTS = 'documents';

    public const string GALLERY_LINKS = 'links';

    /**
     * @var list<string>
     */
    public const array GALLERY_KINDS = [self::GALLERY_MEDIA, self::GALLERY_DOCUMENTS, self::GALLERY_LINKS];

    /**
     * The latest message of the chat, either way.
     *
     * @return HasOne<WhatsappChatMessage, $this>
     */
    public function lastChatMessage(): HasOne
    {
        return $this->hasOne(WhatsappChatMessage::class)->ofMany(['sent_at' => 'max', 'id' => 'max']);
    }

    /**
     * What the AI answers when no message is picked: what the contact sent
     * since the owner's last message (the newest LATEST_RECEIVED of them),
     * else the latest message the contact sent; oldest first.
     *
     * @return list<WhatsappChatMessage>
     */
    public function latestReceivedChatMessages(): array
    {
        $lastSent = $this->chatMessages()->where('from_me', true)->max('sent_at');

        $messages = $this->chatMessages()
            ->where('from_me', false)
            ->when($lastSent !== null, fn (Builder $query) => $query->where('sent_at', '>', $lastSent))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($lastSent !== null ? self::LATEST_RECEIVED : 1)
            ->get();

        if ($messages->isEmpty()) {
            $messages = collect([$this->lastReceivedChatMessage])->filter();
        }

        return $messages->reverse()->values()->all();
    }

    /**
     * The latest message the contact sent.
     *
     * @return HasOne<WhatsappChatMessage, $this>
     */
    public function lastReceivedChatMessage(): HasOne
    {
        return $this->hasOne(WhatsappChatMessage::class)->ofMany(
            ['sent_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('from_me', false),
        );
    }
}
