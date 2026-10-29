<?php

namespace App\Actions\Reminders;

use App\Actions\Recurrences\PauseRecurrence;
use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a reminder (US-9.1) with a soft delete. Its recurrence is paused
 * too, so nothing is notified for it even if it is restored. Its
 * occurrences leave the lists with it.
 *
 * Any member of its workspace with the `reminders` module: reminders are
 * shared there. One of another workspace is not found.
 */
final readonly class DeleteReminder
{
    use AuthorizesRemindersModule, ResolvesWorkspace;

    public function __construct(private PauseRecurrence $pauseRecurrence) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function __invoke(User $actor, Reminder $reminder): void
    {
        $this->authorizeReminders($actor);
        $this->authorizeWorkspaceRecord($actor, $reminder);

        DB::transaction(function () use ($reminder): void {
            if ($reminder->recurrence !== null) {
                ($this->pauseRecurrence)($reminder->recurrence);
            }

            $reminder->delete();
        });
    }
}
