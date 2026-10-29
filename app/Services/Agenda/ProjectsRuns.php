<?php

namespace App\Services\Agenda;

use App\Models\Recurrence;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;

/**
 * The runs of a schedule still to come in a window of the calendar: after
 * now — the past ones already happened and show as what they produced — and
 * at most MAX_RUNS, so a rule that runs every minute cannot flood a month.
 */
trait ProjectsRuns
{
    private const int MAX_RUNS = 400;

    /**
     * @return list<CarbonImmutable>
     */
    private function runsBetween(RecurrenceCalculator $calculator, Recurrence $recurrence, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($recurrence->is_paused) {
            return [];
        }

        $after = $from->subSecond()->max(now()->toImmutable());
        $runs = [];

        while (count($runs) < self::MAX_RUNS
            && ($after = $calculator->nextRunAfter($recurrence, $after)) !== null
            && $after->lessThan($to)) {
            $runs[] = $after;
        }

        return $runs;
    }
}
