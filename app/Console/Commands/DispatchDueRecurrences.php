<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledSlot;
use App\Models\Contracts\Schedulable;
use App\Models\Recurrence;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The single scheduler of every recurrence (US-12.1), run every minute.
 *
 * Each due recurrence gets one `RunScheduledSlot` on its owner's queue for
 * the slot it was due, then `last_run_at` is now and `next_run_at` the next
 * run after now — not after the slot — so the runs missed while the system
 * was down fire a single time on recovery. A `once` rule ends there
 * (`next_run_at` null).
 *
 * Rows are taken with `FOR UPDATE SKIP LOCKED` in batches, each in its own
 * transaction, so two runs at the same time never dispatch the same slot:
 * the second skips what the first holds and, once the first commits, no
 * longer finds them due.
 */
#[Signature('recurrences:dispatch')]
#[Description('Dispatch the due recurrence slots to their owners\' queues')]
class DispatchDueRecurrences extends Command
{
    public const int BATCH_SIZE = 100;

    public function handle(RecurrenceCalculator $calculator): int
    {
        $now = now()->toImmutable();
        $dispatched = 0;

        do {
            $batch = DB::transaction(function () use ($calculator, $now): int {
                $recurrences = Recurrence::query()
                    ->due($now)
                    ->orderBy('next_run_at')
                    ->limit(self::BATCH_SIZE)
                    ->lock('for update skip locked')
                    ->get()
                    ->load(['schedulable', 'frequency', 'weekdays']);

                foreach ($recurrences as $recurrence) {
                    $this->dispatchSlot($recurrence, $calculator, $now);
                }

                return $recurrences->count();
            });

            $dispatched += $batch;
        } while ($batch === self::BATCH_SIZE);

        $this->components->info("Dispatched {$dispatched} recurrence slot(s).");

        return self::SUCCESS;
    }

    private function dispatchSlot(Recurrence $recurrence, RecurrenceCalculator $calculator, CarbonImmutable $now): void
    {
        /** @var Schedulable $owner */
        $owner = $recurrence->schedulable;
        $slot = CarbonImmutable::instance($recurrence->next_run_at)->utc();

        RunScheduledSlot::dispatch($recurrence, $slot)
            ->onQueue($owner->scheduledSlotQueue())
            ->afterCommit();

        $recurrence->forceFill([
            'last_run_at' => $now,
            'next_run_at' => $calculator->nextRunAfter($recurrence, $now),
        ])->save();
    }
}
