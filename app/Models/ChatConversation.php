<?php

namespace App\Models;

use App\Models\Concerns\HasAiTriage;
use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ChatConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One conversation of the unified chat screen, whatever its provider: the
 * entry of the list, with what Jarvis adds on top of the provider (pin,
 * priority, nickname, tags, archive, mute, the contact it belongs to, the
 * reminders and the scheduled messages). `conversable` is the provider's
 * own row (a SlackConversation, a WhatsappTarget), which the provider's
 * driver reads and writes. A WhatsApp entry is a copy of its target, which
 * keeps the state itself (WhatsappTargetObserver, EnsureWhatsappConversation).
 *
 * `kind` is `direct` (one person), `group` (a few people, no name of its
 * own) or `channel` (a named room). `name` and `picture_url` are copied
 * from the provider; `unread_count`, `last_message_at` and `last_message`
 * are kept by the driver as messages arrive. The unread count is Jarvis'
 * own: opening the conversation resets it.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property int $chat_provider_id
 * @property string $conversable_type
 * @property int $conversable_id
 * @property int|null $chat_contact_id
 * @property string $kind
 * @property string|null $name
 * @property string|null $picture_url
 * @property string|null $nickname
 * @property Carbon|null $pinned_at
 * @property int|null $priority
 * @property int|null $ai_urgency how urgent the AI found it (HasAiTriage), apart from `priority`; a WhatsApp entry copies its target's
 * @property string|null $ai_summary
 * @property Carbon|null $ai_triaged_at
 * @property Carbon|null $ai_triaged_through
 * @property Carbon|null $ai_triage_requested_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $muted_until
 * @property int $unread_count
 * @property bool $marked_unread
 * @property Carbon|null $last_message_at
 * @property array{text: string|null, from_me: bool, sender: string|null, kind: string}|null $last_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read ChatProvider $provider
 * @property-read Model $conversable
 * @property-read ChatContact|null $contact
 * @property-read Collection<int, ChatTag> $tags
 * @property-read Collection<int, Reminder> $reminders
 * @property-read Collection<int, ChatScheduledMessage> $scheduledMessages
 */
#[Fillable([
    'user_id', 'chat_provider_id', 'conversable_type', 'conversable_id', 'chat_contact_id', 'kind', 'name', 'picture_url',
    'nickname', 'pinned_at', 'priority', 'archived_at', 'muted_until', 'unread_count', 'marked_unread', 'last_message_at', 'last_message',
    'ai_urgency', 'ai_summary', 'ai_triaged_at', 'ai_triaged_through', 'ai_triage_requested_at',
])]
class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasAiTriage, HasFactory, HasFriendlyId;

    public const string KIND_DIRECT = 'direct';

    public const string KIND_GROUP = 'group';

    public const string KIND_CHANNEL = 'channel';

    public const int MAX_NICKNAME_LENGTH = 100;

    public const int MIN_PRIORITY = 1;

    public const int MAX_PRIORITY = 5;

    /**
     * `muted_until` of a conversation muted for good.
     */
    public const string MUTED_FOREVER = '9999-12-31 00:00:00';

    public static function idPrefix(): string
    {
        return 'cnv';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'priority' => 'integer',
            'archived_at' => 'datetime',
            'muted_until' => 'datetime',
            'unread_count' => 'integer',
            'marked_unread' => 'boolean',
            'last_message_at' => 'datetime',
            'last_message' => 'array',
        ];
    }

    /**
     * The name shown: the user's nickname, else the provider's name.
     */
    public function displayName(): string
    {
        return filled($this->nickname) ? (string) $this->nickname : (string) ($this->name ?? __('Conversation'));
    }

    public function isDirect(): bool
    {
        return $this->kind === self::KIND_DIRECT;
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function isUnread(): bool
    {
        return $this->unread_count > 0 || $this->marked_unread;
    }

    /**
     * The hex color of the priority, from the user's palette (the one of
     * the WhatsApp screen, shared until it moves to the chat layer).
     *
     * @param  array<int, string>|null  $colors  priority => color name
     */
    public function priorityHex(?array $colors = null): ?string
    {
        if ($this->priority === null) {
            return null;
        }

        $colors ??= $this->user->whatsappPriorityColors();

        return WhatsappTarget::PRIORITY_COLORS[$colors[$this->priority] ?? 'gray'] ?? WhatsappTarget::PRIORITY_COLORS['gray'];
    }

    /**
     * The conversations of the user, pinned first (the latest pinned on
     * top), then by the latest message.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ofUser(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('user_id'), $user->id);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function recentFirst(Builder $query): void
    {
        $query->orderByRaw('pinned_at desc nulls last')
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('id');
    }

    /**
     * Pinned, then by priority (1 first, none last), then the latest.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function priorityFirst(Builder $query): void
    {
        $query->orderByRaw('pinned_at desc nulls last')
            ->orderByRaw('priority asc nulls last')
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('id');
    }

    /**
     * Pinned, then by the AI's urgency (most urgent first, none last),
     * then the latest.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function aiUrgencyFirst(Builder $query): void
    {
        $query->orderByRaw('pinned_at desc nulls last')
            ->orderByRaw('ai_urgency asc nulls last')
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ChatProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ChatProvider::class, 'chat_provider_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function conversable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ChatContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(ChatContact::class, 'chat_contact_id');
    }

    /**
     * @return BelongsToMany<ChatTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ChatTag::class, 'chat_conversation_tag')->withTimestamps()->orderBy('name');
    }

    /**
     * The reminders made from this conversation, with the id of the message
     * each one quotes last.
     *
     * @return BelongsToMany<Reminder, $this>
     */
    public function reminders(): BelongsToMany
    {
        return $this->belongsToMany(Reminder::class, 'chat_conversation_reminders')->withPivot('message_id')->withTimestamps();
    }

    /**
     * @return HasMany<ChatScheduledMessage, $this>
     */
    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ChatScheduledMessage::class);
    }
}
