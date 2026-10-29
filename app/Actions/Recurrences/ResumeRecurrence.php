<?php

namespace App\Actions\Recurrences;

use App\Models\Recurrence;
use App\Services\Recurrences\RecurrenceCalculator;

/**
 * Starts a paused recurrence again (US-12.1). The next run is computed from
 * now, so the runs missed while it was paused are skipped rather than fired
 * on resume. Resuming a running rule changes nothing.
 */
final readonly class ResumeRecurrence
{
    public function __construct(private RecurrenceCalculator $calculator) {}

    public function __invoke(Recurrence $recurrence): Recurrence
    {
        if (! $recurrence->is_paused) {
            return $recurrence;
        }

        $recurrence->fill([
            'is_paused' => false,
            'next_run_at' => $this->calculator->nextRunAfter($recurrence, now()->toImmutable()),
        ])->save();

        return $recurrence;
    }
}
