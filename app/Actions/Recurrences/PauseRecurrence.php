<?php

namespace App\Actions\Recurrences;

use App\Models\Recurrence;

/**
 * Stops a recurrence from running (US-12.1): `Recurrence::due()` skips a
 * paused rule, whatever its `next_run_at`. `ResumeRecurrence` starts it
 * again. Pausing twice changes nothing.
 */
final readonly class PauseRecurrence
{
    public function __invoke(Recurrence $recurrence): Recurrence
    {
        $recurrence->update(['is_paused' => true]);

        return $recurrence;
    }
}
