<?php

namespace App\Models;

use App\Events\ChatConversationUpdated;
use App\Jobs\SendChatScheduledSlot;
use App\Models\Concerns\HasFriendlyId;
use App\Models\Concerns\HasRecurrence;
use App\Models\Contracts\Schedulable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ChatScheduledMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A text the user asked to send to a conversation later, whatever its
 * provider: once or repeating, as its recurrence says (US-12.1), the text
 * written by the user or, from `ai_prompt`, by the AI at each sending
 * (SendChatScheduledSlot), or a poll (`poll`, on the channels that have
 * polls; the body is then its question). Each sending is kept
 * (ChatScheduledDelivery).
 * Listed in the conversation and on the scheduled messages screen until
 * done, cancelled there.
 *
 * The ones scheduled before recurrences came have `send_at` and no
 * recurrence: `chats:send-scheduled` sends them when due.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $chat_conversation_id
 * @property int $user_id
 * @property string|null $body the text, unless the AI writes it
 * @property string|null $ai_prompt what the AI is asked to write at each sending
 * @property array{name: string, options: list<string>, selectable_count: int}|null $poll a poll sent instead of the text
 * @property string|null $thread_id
 * @property Carbon|null $send_at only on the ones without a recurrence
 * @property Carbon|null $sent_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChatConversation $conversation
 * @property-read User $user
 * @property-read Collection<int, ChatScheduledDelivery> $deliveries
 */
#[Fillable(['chat_conversation_id', 'user_id', 'body', 'ai_prompt', 'poll', 'thread_id', 'send_at', 'sent_at', 'cancelled_at', 'failed_at', 'error'])]
class ChatScheduledMessage extends Model implements Schedulable
{
    /** @use HasFactory<ChatScheduledMessageFactory> */
    use HasFactory, HasFriendlyId, HasRecurrence;

    public const int MAX_BODY_LENGTH = 4000;

    public const int MAX_AI_PROMPT_LENGTH = 2000;

    public static function idPrefix(): string
    {
        return 'csm';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
            'poll' => 'array',
        ];
    }

    /**
     * Sends one occurrence, in the background (SendChatScheduledSlot).
     */
    public function runScheduledSlot(CarbonImmutable $slot): void
    {
        if ($this->cancelled_at !== null) {
            return;
        }

        SendChatScheduledSlot::dispatch($this, $slot);
    }

    public function ownerTimezone(): string
    {
        return $this->user->timezone;
    }

    public function scheduledSlotQueue(): string
    {
        return ChatConversationUpdated::QUEUE;
    }

    public function writesWithAi(): bool
    {
        return filled($this->ai_prompt);
    }

    /**
     * Whether it sends a poll instead of a text.
     */
    public function sendsPoll(): bool
    {
        return $this->poll !== null;
    }

    /**
     * Whether it goes more than once (a recurrence other than `once`).
     */
    public function repeats(): bool
    {
        return $this->recurrence !== null && $this->recurrence->frequency->slug !== RecurrenceFrequency::ONCE;
    }

    /**
     * When it goes next: the recurrence's next run, or `send_at`.
     */
    public function nextSendAt(): ?CarbonInterface
    {
        return $this->recurrence !== null ? $this->recurrence->next_run_at : $this->send_at;
    }

    /**
     * Not sent, cancelled or failed yet (once), or with a run ahead
     * (repeating).
     */
    public function isPending(): bool
    {
        if ($this->sent_at !== null || $this->cancelled_at !== null || $this->failed_at !== null) {
            return false;
        }

        return $this->recurrence === null || $this->recurrence->next_run_at !== null;
    }

    /**
     * Not sent, cancelled or failed yet, and, with a recurrence, with a run
     * ahead.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('sent_at')->whereNull('cancelled_at')->whereNull('failed_at')
            ->where(fn (Builder $query) => $query->whereDoesntHave('recurrence')->orWhereHas('recurrence', fn (Builder $query) => $query->whereNotNull('next_run_at')));
    }

    /**
     * Pending, due, and without a recurrence (the recurrences run their own).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->pending()->whereDoesntHave('recurrence')->where('send_at', '<=', now());
    }

    /**
     * Soonest first: by the next run of the recurrence, else by `send_at`.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function soonestFirst(Builder $query): void
    {
        $query->orderByRaw('coalesce((select next_run_at from recurrences where recurrences.schedulable_type = ? and recurrences.schedulable_id = chat_scheduled_messages.id), chat_scheduled_messages.send_at) asc nulls last', [$this->getMorphClass()])
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ChatScheduledDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ChatScheduledDelivery::class);
    }
}
