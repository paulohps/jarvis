<?php

namespace App\Actions\Reminders;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Edits a reminder (US-9.1) with the same rules as CreateReminder: its
 * schedule is replaced — or removed, for an undated task — and its open
 * occurrence moves with it. The completed occurrences stay as they were,
 * and so does the creator, who keeps receiving `reminder_due`. A
 * `workspace` in the input moves it to that one of the actor's.
 *
 * Any member of its workspace with the `reminders` module: reminders are
 * shared there. One of another workspace is not found.
 */
final readonly class UpdateReminder
{
    use AuthorizesRemindersModule, SavesReminder;

    public function __construct(private SaveRecurrence $saveRecurrence) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function __invoke(User $actor, Reminder $reminder, array $input): Reminder
    {
        $this->authorizeReminders($actor);
        $this->authorizeWorkspaceRecord($actor, $reminder);

        return $this->persist($this->saveRecurrence, $actor, $input, $reminder);
    }
}
