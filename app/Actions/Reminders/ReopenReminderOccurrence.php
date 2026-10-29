<?php

namespace App\Actions\Reminders;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Takes back the completion of an occurrence (US-9.1): it is open again,
 * without who completed it nor when. The next occurrence of a repeating
 * reminder, if already created, stays.
 *
 * Any member of the reminder's workspace with the `reminders` module:
 * reminders are shared there. One of another workspace is not found. The
 * occurrences of a deleted reminder cannot be reopened.
 */
final readonly class ReopenReminderOccurrence
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

        if ($occurrence->reminder->trashed()) {
            throw ValidationException::withMessages([
                'occurrence' => __('This reminder was deleted.'),
            ]);
        }

        $occurrence->update(['completed_at' => null, 'completed_by_id' => null]);

        return $occurrence;
    }
}
