<?php

namespace App\Models;

/**
 * What an item of the agenda is: a reminder or task — a moment, completed
 * with its checkbox — or an event, which takes time (a duration, or the
 * whole day) and is not completed.
 */
enum ReminderKind: string
{
    case Reminder = 'reminder';

    case Event = 'event';
}
