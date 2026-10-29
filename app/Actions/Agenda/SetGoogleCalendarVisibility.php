<?php

namespace App\Actions\Agenda;

use App\Models\GoogleCalendar;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shows a Google calendar on its owner's agenda, or hides it.
 */
final readonly class SetGoogleCalendarVisibility
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, GoogleCalendar $calendar, bool $isVisible): GoogleCalendar
    {
        if ($calendar->account->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        $calendar->update(['is_visible' => $isVisible]);

        return $calendar;
    }
}
