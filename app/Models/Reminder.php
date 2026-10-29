<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasFriendlyId;
use App\Models\Concerns\HasRecurrence;
use App\Models\Contracts\Schedulable;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\ReminderDue;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An item of a workspace's agenda, optionally dated and recurring
 * (US-9.1): a reminder or task, completed with its checkbox, or an event,
 * which lasts `duration_minutes` or the whole day. It is seen and acted on
 * by the members of its workspace with the `reminders` module
 * (BelongsToWorkspace), and only they can be made responsible for it. Each due date is a
 * `ReminderOccurrence` (an event's start); an undated task has a single
 * occurrence without `due_at`. An all-day item is due at ALL_DAY_TIME on
 * the creator's wall clock, so its notice does not come at midnight.
 *
 * Registered in the morph map as a `recurrences.schedulable` owner. Soft
 * deleted owners are skipped by `Recurrence::due()`.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $workspace_id
 * @property string $title
 * @property string|null $note
 * @property ReminderKind $kind
 * @property bool $is_all_day
 * @property int|null $duration_minutes
 * @property int $created_by_id
 * @property int|null $assigned_to_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $createdBy
 * @property-read User|null $assignedTo
 */
#[Fillable(['workspace_id', 'title', 'note', 'kind', 'is_all_day', 'duration_minutes', 'created_by_id', 'assigned_to_id'])]
class Reminder extends Model implements Schedulable
{
    /** @use HasFactory<ReminderFactory> */
    use BelongsToWorkspace, HasFactory, HasFriendlyId, HasRecurrence, SoftDeletes;

    /**
     * The wall-clock time an all-day item is due at, and notified.
     */
    public const string ALL_DAY_TIME = '09:00';

    protected $table = 'reminders';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'reminder',
        'is_all_day' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'is_all_day' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    public static function idPrefix(): string
    {
        return 'rem';
    }

    public static function workspaceModule(): string
    {
        return Module::REMINDERS;
    }

    /**
     * The slot is due (US-9.2): its occurrence is taken — or created, when
     * the rule changed since — and, unless it was completed ahead of time,
     * the recipient gets `reminder_due`. `notified_at` is claimed with a
     * conditional update, so a slot run twice, even at the same time,
     * notifies once. A repeating reminder then gets its next occurrence. A
     * deleted reminder does nothing.
     */
    public function runScheduledSlot(CarbonImmutable $slot): void
    {
        if ($this->trashed()) {
            return;
        }

        $slot = $slot->utc();
        $occurrence = $this->occurrences()->firstOrCreate(['due_at' => $slot]);

        $claimed = ReminderOccurrence::query()
            ->whereKey($occurrence->id)
            ->whereNull('completed_at')
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]);

        $recipient = $this->recipient();

        if ($claimed === 1 && $recipient !== null) {
            app(NotificationCenter::class)->dispatch(new ReminderDue($occurrence->refresh()->setRelation('reminder', $this)), $recipient);
        }

        $this->ensureNextOccurrence($slot);
    }

    /**
     * Who receives `reminder_due`: the person responsible for it, or the
     * creator while nobody is — as long as they still see it (a member of
     * its workspace with the module). Someone who left the workspace is
     * skipped for the creator; null when neither sees it anymore, so the
     * title never reaches someone outside the workspace.
     */
    public function recipient(): ?User
    {
        foreach ([$this->assignedTo, $this->createdBy] as $candidate) {
            if ($candidate !== null && $this->isVisibleTo($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Who may be responsible for a reminder of `$workspace`: its active
     * members with the `reminders` module there, by name.
     *
     * @return Collection<int, User>
     */
    public static function assignees(Workspace $workspace): Collection
    {
        return $workspace->membersWithModule(Module::REMINDERS)
            ->whereNull('users.deactivated_at')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();
    }

    public function ownerTimezone(): string
    {
        return $this->createdBy->timezone;
    }

    /**
     * Whether it is an event, which takes time and is not completed.
     */
    public function isEvent(): bool
    {
        return $this->kind === ReminderKind::Event;
    }

    /**
     * When an occurrence due at `$dueAt` ends: after the event's duration;
     * null for a reminder, a moment, or an all-day item.
     */
    public function endsAt(?CarbonInterface $dueAt): ?CarbonImmutable
    {
        if ($dueAt === null || ! $this->isEvent() || $this->is_all_day || $this->duration_minutes === null) {
            return null;
        }

        return CarbonImmutable::instance($dueAt)->addMinutes($this->duration_minutes);
    }

    /**
     * Whether it is due a single time: its recurrence is `once`.
     */
    public function isOnce(): bool
    {
        return $this->recurrence?->recurrence_frequency_id === RecurrenceFrequency::idFor(RecurrenceFrequency::ONCE);
    }

    /**
     * Whether it repeats: it has a recurrence other than `once`.
     */
    public function isRecurring(): bool
    {
        return $this->recurrence !== null && ! $this->isOnce();
    }

    public function scheduledSlotQueue(): string
    {
        return 'default';
    }

    /**
     * Makes sure a repeating reminder has its occurrence of the first run
     * after `$after` — the recurrence's `next_run_at`, or the run after
     * `$after` when that one is not later (an occurrence completed ahead of
     * time). The `(reminder_id, due_at)` unique keeps it from being created
     * twice. Nothing for a `once` or undated reminder, a paused rule or a
     * rule with no run left.
     */
    public function ensureNextOccurrence(CarbonInterface $after): ?ReminderOccurrence
    {
        $this->loadMissing('recurrence');
        $recurrence = $this->recurrence;

        if ($recurrence === null || ! $this->isRecurring() || $recurrence->is_paused || $recurrence->next_run_at === null) {
            return null;
        }

        $after = CarbonImmutable::instance($after)->utc();
        $next = CarbonImmutable::instance($recurrence->next_run_at)->utc();

        if ($next->lessThanOrEqualTo($after)) {
            $next = app(RecurrenceCalculator::class)->nextRunAfter($recurrence, $after);
        }

        return $next === null ? null : $this->occurrences()->firstOrCreate(['due_at' => $next]);
    }

    /**
     * @return HasMany<ReminderOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ReminderOccurrence::class);
    }

    /**
     * The person responsible for it, who receives `reminder_due`; null
     * while nobody is.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * The creator, who receives `reminder_due` while nobody is responsible
     * (US-9.2).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
