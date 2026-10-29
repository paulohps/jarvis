<?php

namespace App\Services\Agenda;

use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * One thing on the agenda's calendar, from any of its sources: a reminder
 * or event of the agenda, the sending of an invoice, a scheduled WhatsApp
 * message… A later run of a schedule that has not happened yet is
 * `isProjected`. Only a reminder's occurrence can be completed here; the
 * others open their own module (`url`) or the agenda's form (`reminder`).
 * Shared data names its `workspace`, drawn in the color the reader sees it
 * in; personal sources (WhatsApp, Google) have none.
 */
final readonly class CalendarEntry
{
    public function __construct(
        public string $key,
        public string $source,
        public string $title,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt = null,
        public bool $isAllDay = false,
        public ?string $subtitle = null,
        public ?string $url = null,
        public ?Reminder $reminder = null,
        public ?ReminderOccurrence $occurrence = null,
        public bool $isProjected = false,
        public ?Workspace $workspace = null,
    ) {}

    /**
     * Whether its checkbox completes or reopens it: a reminder's occurrence.
     */
    public function isCompletable(): bool
    {
        return $this->occurrence !== null && $this->reminder !== null && ! $this->reminder->isEvent();
    }

    public function isCompleted(): bool
    {
        return $this->occurrence?->completed_at !== null;
    }

    /**
     * An open reminder whose time has passed.
     */
    public function isOverdue(): bool
    {
        return $this->isCompletable() && ! $this->isCompleted() && $this->startsAt->isPast();
    }

    /**
     * The day it is on, `Y-m-d` in `$timezone`.
     */
    public function day(string $timezone): string
    {
        return $this->startsAt->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * How long it is drawn on a timeline: its duration, or `$minimum`
     * minutes for a moment.
     */
    public function displayMinutes(int $minimum = 30): int
    {
        if ($this->endsAt === null) {
            return $minimum;
        }

        return max($minimum, (int) $this->startsAt->diffInMinutes($this->endsAt));
    }
}
