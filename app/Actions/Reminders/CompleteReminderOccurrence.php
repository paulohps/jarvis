<?php

namespace App\Actions\Reminders;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks an occurrence of a reminder as done (US-9.1): who completed it and
 * when. For a repeating reminder the next occurrence is created at the
 * recurrence's next run (US-9.2), only once: completing again changes
 * nothing, and the `(reminder_id, due_at)` unique keeps two requests at
 * the same time from creating it twice.
 *
 * Any member of the reminder's workspace with the `reminders` module:
 * reminders are shared there. One of another workspace is not found. The
 * occurrences of a deleted reminder cannot be completed.
 */
final readonly class CompleteReminderOccurrence
{
    use AuthorizesRemindersModule, ResolvesWorkspace;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ReminderOccurrence $occurrence): ReminderOccurrence
    {
        $this->authorizeReminders($actor);
        $this->authorizeWorkspaceRecord($actor, $occurrence);

        $reminder = $occurrence->reminder;

        if ($reminder->trashed()) {
            throw ValidationException::withMessages([
                'occurrence' => __('This reminder was deleted.'),
            ]);
        }

        return DB::transaction(function () use ($actor, $occurrence, $reminder): ReminderOccurrence {
            ReminderOccurrence::query()
                ->whereKey($occurrence->id)
                ->whereNull('completed_at')
                ->update(['completed_at' => now(), 'completed_by_id' => $actor->id]);

            $occurrence->refresh();

            if ($occurrence->due_at !== null) {
                $reminder->ensureNextOccurrence($occurrence->due_at);
            }

            return $occurrence;
        });
    }
}
