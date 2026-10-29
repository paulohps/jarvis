<?php

namespace App\Http\Controllers\Api\V1\Reminders;

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\ReopenReminderOccurrence;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReminderOccurrenceResource;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Completing and reopening an occurrence of a reminder in the apps
 * (US-9.1), through the same Actions as the web checkbox. Completing one of
 * a repeating reminder creates its next occurrence. One of a reminder of a
 * workspace the reader does not see is not found.
 */
class ReminderOccurrenceController extends Controller
{
    /**
     * `POST /api/v1/reminder-occurrences/{occurrence}/complete`.
     */
    public function complete(Request $request, ReminderOccurrence $occurrence, CompleteReminderOccurrence $complete): ReminderOccurrenceResource
    {
        return $this->occurrenceResource($request, $complete($this->actor($request), $occurrence));
    }

    /**
     * `POST /api/v1/reminder-occurrences/{occurrence}/reopen`.
     */
    public function reopen(Request $request, ReminderOccurrence $occurrence, ReopenReminderOccurrence $reopen): ReminderOccurrenceResource
    {
        return $this->occurrenceResource($request, $reopen($this->actor($request), $occurrence));
    }

    private function occurrenceResource(Request $request, ReminderOccurrence $occurrence): ReminderOccurrenceResource
    {
        $occurrence->load(['completedBy', 'reminder.workspace', 'reminder.createdBy', 'reminder.recurrence.frequency', 'reminder.recurrence.weekdays']);

        return (new ReminderOccurrenceResource($occurrence))->additional(['meta' => ['timezone' => $this->actor($request)->timezone]]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
