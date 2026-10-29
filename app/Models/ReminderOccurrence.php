<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ReminderOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One due date of a reminder (`due_at` null for an undated task). The
 * filters of US-9.1 split the occurrences with no overlap: `completed`,
 * `overdue` (past and open) and `pending` (open and not past, undated
 * included). `notified_at` keeps `reminder_due` from firing twice. It
 * belongs to its reminder's workspace: whoever sees the reminder sees it
 * (`visibleTo`).
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $reminder_id
 * @property Carbon|null $due_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $completed_at
 * @property int|null $completed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reminder $reminder
 * @property-read User|null $completedBy
 */
#[Fillable(['reminder_id', 'due_at', 'notified_at', 'completed_at', 'completed_by_id'])]
class ReminderOccurrence extends Model
{
    /** @use HasFactory<ReminderOccurrenceFactory> */
    use HasFactory, HasFriendlyId;

    public static function idPrefix(): string
    {
        return 'remo';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Open and not past due: undated, or due now or later.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('completed_at'))
            ->where(fn (Builder $query) => $query
                ->whereNull($this->qualifyColumn('due_at'))
                ->orWhere($this->qualifyColumn('due_at'), '>=', now()));
    }

    /**
     * Open and past due.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('completed_at'))
            ->where($this->qualifyColumn('due_at'), '<', now());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('completed_at'));
    }

    /**
     * Only the occurrences of the reminders `$user` sees.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('reminder', fn (Builder $reminders) => $reminders->withTrashed()->visibleTo($user));
    }

    /**
     * Whether `$user` sees it: they see its reminder.
     */
    public function isVisibleTo(User $user): bool
    {
        return $this->reminder->isVisibleTo($user);
    }

    /**
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
