<?php

namespace App\Models;

use App\Models\Casts\TimeOfDay;
use Carbon\CarbonInterface;
use Database\Factories\RecurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The repetition rule shared by invoice recurrences, WhatsApp messages and
 * reminders (Key Concept "Recurrence"), owned through the `schedulable`
 * morph. `time_of_day` is local to `timezone` (the owner's at save time);
 * the instants (`starts_at`, `ends_at`, `next_run_at`, `last_run_at`) are
 * UTC. `next_run_at` is null once the rule is finished.
 *
 * @property int $id
 * @property string $schedulable_type
 * @property int $schedulable_id
 * @property int $recurrence_frequency_id
 * @property string|null $time_of_day
 * @property int|null $day_of_month
 * @property bool $is_last_day_of_month
 * @property string|null $cron_expression
 * @property string $timezone
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property bool $is_paused
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $schedulable
 * @property-read RecurrenceFrequency $frequency
 */
#[Fillable([
    'recurrence_frequency_id', 'time_of_day', 'day_of_month', 'is_last_day_of_month', 'cron_expression',
    'timezone', 'starts_at', 'ends_at', 'next_run_at', 'last_run_at', 'is_paused',
])]
class Recurrence extends Model
{
    /** @use HasFactory<RecurrenceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time_of_day' => TimeOfDay::class,
            'day_of_month' => 'integer',
            'is_last_day_of_month' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'is_paused' => 'boolean',
        ];
    }

    /**
     * Recurrences the scheduler must run: not paused, with `next_run_at` at
     * or before `$now`, and whose owner still exists and is not
     * soft-deleted.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDue(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->where($this->qualifyColumn('is_paused'), false)
            ->whereNotNull($this->qualifyColumn('next_run_at'))
            ->where($this->qualifyColumn('next_run_at'), '<=', $now ?? now())
            ->whereHasMorph('schedulable', '*');
    }

    /**
     * The invoice recurrence, WhatsApp message or reminder this rule
     * belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<RecurrenceFrequency, $this>
     */
    public function frequency(): BelongsTo
    {
        return $this->belongsTo(RecurrenceFrequency::class, 'recurrence_frequency_id');
    }

    /**
     * The days a weekly rule runs on.
     *
     * @return BelongsToMany<Weekday, $this>
     */
    public function weekdays(): BelongsToMany
    {
        return $this->belongsToMany(Weekday::class)->withTimestamps();
    }
}
