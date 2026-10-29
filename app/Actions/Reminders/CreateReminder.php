<?php

namespace App\Actions\Reminders;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Creates a reminder or task (US-9.1; Workflow 12): a title, an optional
 * note, an optional date and time and an optional recurrence (see
 * SavesReminder for the input and the occurrence it gets). The creator is
 * who receives `reminder_due` when it is due (US-9.2).
 *
 * It goes to the `workspace` of the input — one of the creator's with the
 * `reminders` module — or to their default one, and is shared by the
 * members of that workspace with the module.
 */
final readonly class CreateReminder
{
    use AuthorizesRemindersModule, SavesReminder;

    public function __construct(private SaveRecurrence $saveRecurrence) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): Reminder
    {
        $this->authorizeReminders($actor);

        return $this->persist($this->saveRecurrence, $actor, $input, null);
    }
}
