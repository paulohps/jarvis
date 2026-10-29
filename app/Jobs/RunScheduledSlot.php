<?php

namespace App\Jobs;

use App\Models\Contracts\Schedulable;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * One occurrence of a recurrence (US-12.1), dispatched by
 * `recurrences:dispatch` on the owner's queue: the owner does the module's
 * work in `runScheduledSlot()`. `$slot` is the UTC instant the occurrence
 * was due, the owner's idempotency key.
 *
 * An owner deleted (or soft-deleted) after the dispatch runs nothing, and
 * neither does a recurrence deleted meanwhile.
 */
#[DeleteWhenMissingModels]
class RunScheduledSlot implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Recurrence $recurrence,
        public CarbonImmutable $slot,
    ) {}

    public function handle(): void
    {
        $owner = $this->recurrence->schedulable;

        if (! $owner instanceof Schedulable) {
            return;
        }

        $owner->runScheduledSlot($this->slot);
    }
}
