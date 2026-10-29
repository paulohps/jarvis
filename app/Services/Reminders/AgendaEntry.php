<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use Carbon\CarbonImmutable;

/**
 * One line of the reminders agenda: an open occurrence, or a later run of a
 * repeating reminder that has no occurrence yet (`occurrence` null). Such a
 * projected run only shows what is coming: it is completed once its
 * occurrence exists, when the run before it is due.
 */
final readonly class AgendaEntry
{
    public function __construct(
        public Reminder $reminder,
        public ?CarbonImmutable $dueAt,
        public ?ReminderOccurrence $occurrence = null,
    ) {}

    public function isProjected(): bool
    {
        return $this->occurrence === null;
    }

    public function isOverdue(): bool
    {
        return $this->dueAt?->isPast() === true;
    }
}
