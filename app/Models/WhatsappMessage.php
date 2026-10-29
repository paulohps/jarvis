<?php

namespace App\Models;

use App\Actions\Whatsapp\SendWhatsAppMessage;
use App\Jobs\WriteScheduledWhatsappMessage;
use App\Models\Concerns\HasFriendlyId;
use App\Models\Concerns\HasRecurrence;
use App\Models\Contracts\Schedulable;
use App\Services\Whatsapp\WhatsappTemplateSchema;
use Carbon\CarbonImmutable;
use Database\Factories\WhatsappMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A one-off or recurring WhatsApp message (US-7.3). `body` is a `{% %}`
 * template rendered as plain text; a `once` message may be cancelled before
 * it runs (`cancelled_at`). With an `ai_prompt` there is no body: at each
 * run the AI writes the message to each target from that prompt
 * (WriteScheduledWhatsappMessage). With a `poll` it sends that poll
 * instead, the body being its question, as typed (no template).
 *
 * Registered in the morph map as a `recurrences.schedulable` owner. Soft
 * deleted owners are skipped by `Recurrence::due()`.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property int $whatsapp_account_id
 * @property string $body the text, or the caption of the file
 * @property string|null $ai_prompt what the AI is asked to write at each run, instead of the body
 * @property array{name: string, options: list<string>, selectable_count: int}|null $poll a poll sent instead of the body (`selectable_count` 1 for one answer, 0 for any)
 * @property string|null $media_path a file to send, on the private disk (WhatsappChatMedia)
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property int|null $media_size
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read WhatsappAccount $account
 * @property-read Collection<int, WhatsappTarget> $targets
 */
#[Fillable(['user_id', 'whatsapp_account_id', 'body', 'ai_prompt', 'poll', 'media_path', 'media_mime', 'media_name', 'media_size', 'cancelled_at'])]
class WhatsappMessage extends Model implements Schedulable
{
    /** @use HasFactory<WhatsappMessageFactory> */
    use HasFactory, HasFriendlyId, HasRecurrence, SoftDeletes;

    protected $table = 'whatsapp_messages';

    public static function idPrefix(): string
    {
        return 'wmsg';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
            'media_size' => 'integer',
            'poll' => 'array',
        ];
    }

    /**
     * Sends the message to every target for the slot (US-7.3, US-7.4): one
     * `SendWhatsAppMessage` per target, `source = scheduled`, with the body
     * written out for that target. A delivery is unique per message, target
     * and slot, so running a slot again sends nothing twice. A cancelled or
     * deleted message sends nothing.
     *
     * A message the AI writes queues one WriteScheduledWhatsappMessage per
     * target instead, each writing and sending its own. A poll goes as it
     * is to every target.
     */
    public function runScheduledSlot(CarbonImmutable $slot): void
    {
        if ($this->trashed() || $this->isCancelled()) {
            return;
        }

        $this->loadMissing(['account.status', 'account.user', 'user', 'targets']);

        if ($this->writesWithAi()) {
            foreach ($this->targets->sortBy('id') as $target) {
                WriteScheduledWhatsappMessage::dispatch($this, $target, $slot);
            }

            return;
        }

        $schema = app(WhatsappTemplateSchema::class);
        $send = app(SendWhatsAppMessage::class);

        foreach ($this->targets->sortBy('id') as $target) {
            $send(
                $this->account,
                $target,
                $this->sendsPoll() ? $this->body : $schema->render($this->body, $target, $this->user),
                WhatsappDeliverySource::SCHEDULED,
                $this,
                $slot,
                media: $this->media_path !== null ? ['path' => $this->media_path, 'mime' => (string) $this->media_mime, 'name' => $this->media_name] : null,
                payload: $this->sendsPoll() ? ['poll' => $this->poll] : null,
            );
        }
    }

    public function ownerTimezone(): string
    {
        return $this->user->timezone;
    }

    public function scheduledSlotQueue(): string
    {
        return 'whatsapp';
    }

    /**
     * Whether the message runs a single time: its recurrence is `once`.
     */
    public function isOnce(): bool
    {
        return $this->recurrence?->recurrence_frequency_id === RecurrenceFrequency::idFor(RecurrenceFrequency::ONCE);
    }

    /**
     * Whether the AI writes the message at each run, from `ai_prompt`.
     */
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

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Where the schedule stands, as a status slug: `cancelled`, `paused`,
     * `finished` (no run left) or `active`.
     */
    public function scheduleState(): string
    {
        return match (true) {
            $this->isCancelled() => 'cancelled',
            (bool) $this->recurrence?->is_paused => 'paused',
            $this->recurrence?->next_run_at === null => 'finished',
            default => 'active',
        };
    }

    /**
     * The input of SendWhatsappNow for this message as it is saved: its
     * account, its targets, its body and its poll.
     *
     * @return array{account: string, targets: list<string>, numbers: list<string>, body: string, poll: array{question: string, options: list<string>, multiple: bool}|null}
     */
    public function sendingInput(): array
    {
        return [
            'account' => $this->account->friendly_id,
            'targets' => array_values($this->targets()->orderBy('whatsapp_targets.id')->pluck('whatsapp_targets.friendly_id')->all()),
            'numbers' => [],
            'body' => $this->body,
            'poll' => $this->poll === null ? null : ['question' => $this->poll['name'], 'options' => $this->poll['options'], 'multiple' => $this->poll['selectable_count'] !== 1],
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The account the message is sent from.
     *
     * @return BelongsTo<WhatsappAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    /**
     * @return BelongsToMany<WhatsappTarget, $this>
     */
    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappTarget::class)->withTimestamps();
    }

    /**
     * @return HasMany<WhatsappDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WhatsappDelivery::class);
    }
}
