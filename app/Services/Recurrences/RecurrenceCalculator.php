<?php

namespace App\Services\Recurrences;

use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Weekday;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use InvalidArgumentException;

/**
 * The single place where the next run of a recurrence is computed (US-12.1),
 * shared by invoices, WhatsApp messages and reminders.
 *
 * The wall-clock rules (`time_of_day`, weekdays, day of month, cron) are
 * read in the recurrence's `timezone`, the owner's, so 09:00 stays 09:00
 * across DST changes; the result is a UTC instant. A run is always strictly
 * after `$after`, never before `starts_at` and never after `ends_at`: past
 * the end, or once the single run of `once` is behind, there is none.
 *
 * A wall-clock time skipped by a DST jump runs at the shifted time PHP
 * gives it (02:30 on a spring-forward night runs at 03:30); a repeated one
 * runs on its first occurrence.
 */
final class RecurrenceCalculator
{
    /**
     * The day runs of a daily, weekly or monthly rule happen at when no
     * `time_of_day` is set.
     */
    public const string DEFAULT_TIME_OF_DAY = '00:00';

    /**
     * The ISO-8601 number of each weekday slug (1 = Monday … 7 = Sunday).
     *
     * @var array<string, int>
     */
    public const array ISO_WEEKDAYS = [
        Weekday::MONDAY => 1,
        Weekday::TUESDAY => 2,
        Weekday::WEDNESDAY => 3,
        Weekday::THURSDAY => 4,
        Weekday::FRIDAY => 5,
        Weekday::SATURDAY => 6,
        Weekday::SUNDAY => 7,
    ];

    /**
     * The first run strictly after `$after`, in UTC, or null when the rule
     * has no run left.
     */
    public function nextRunAfter(Recurrence $recurrence, CarbonImmutable $after): ?CarbonImmutable
    {
        $timezone = $recurrence->timezone;
        $startsAt = CarbonImmutable::instance($recurrence->starts_at)->utc();
        $endsAt = $recurrence->ends_at === null ? null : CarbonImmutable::instance($recurrence->ends_at)->utc();

        // Strictly after this instant: `$after`, or just before `starts_at` so the start itself may run.
        $from = $after->utc()->max($startsAt->subSecond());

        $next = match ($recurrence->frequency->slug) {
            RecurrenceFrequency::ONCE => $startsAt->greaterThan($after) ? $startsAt : null,
            RecurrenceFrequency::DAILY => $this->nextDaily($recurrence, $from, $timezone),
            RecurrenceFrequency::WEEKLY => $this->nextWeekly($recurrence, $from, $timezone),
            RecurrenceFrequency::MONTHLY => $this->nextMonthly($recurrence, $from, $timezone),
            RecurrenceFrequency::CUSTOM_CRON => $this->nextCron($recurrence, $from, $timezone),
            default => throw new InvalidArgumentException("Unknown recurrence frequency [{$recurrence->frequency->slug}]."),
        };

        if ($next === null || ($endsAt !== null && $next->greaterThan($endsAt))) {
            return null;
        }

        return $next->utc();
    }

    /**
     * The next `$count` runs strictly after `$after`, in UTC, fewer when the
     * rule ends first (the preview of the recurrence picker).
     *
     * @return list<CarbonImmutable>
     */
    public function nextRuns(Recurrence $recurrence, CarbonImmutable $after, int $count): array
    {
        $runs = [];

        while (count($runs) < $count && ($next = $this->nextRunAfter($recurrence, $after)) !== null) {
            $runs[] = $next;
            $after = $next;
        }

        return $runs;
    }

    private function nextDaily(Recurrence $recurrence, CarbonImmutable $from, string $timezone): CarbonImmutable
    {
        $day = $from->setTimezone($timezone)->startOfDay();

        for ($offset = 0; ; $offset++) {
            $candidate = $this->atTimeOfDay($recurrence, $day->addDays($offset), $timezone);

            if ($candidate->greaterThan($from)) {
                return $candidate;
            }
        }
    }

    private function nextWeekly(Recurrence $recurrence, CarbonImmutable $from, string $timezone): ?CarbonImmutable
    {
        $isoWeekdays = $recurrence->weekdays
            ->map(fn (Weekday $weekday): ?int => self::ISO_WEEKDAYS[$weekday->slug] ?? null)
            ->filter()
            ->values()
            ->all();

        if ($isoWeekdays === []) {
            return null;
        }

        $day = $from->setTimezone($timezone)->startOfDay();

        // Eight days: today's slot may already be behind, so the same weekday next week counts.
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $day->addDays($offset);

            if (! in_array($date->isoWeekday(), $isoWeekdays, true)) {
                continue;
            }

            $candidate = $this->atTimeOfDay($recurrence, $date, $timezone);

            if ($candidate->greaterThan($from)) {
                return $candidate;
            }
        }

        return null;
    }

    private function nextMonthly(Recurrence $recurrence, CarbonImmutable $from, string $timezone): CarbonImmutable
    {
        $month = $from->setTimezone($timezone)->startOfMonth();
        $dayOfMonth = $recurrence->day_of_month
            ?? CarbonImmutable::instance($recurrence->starts_at)->setTimezone($timezone)->day;

        for ($offset = 0; ; $offset++) {
            $monthStart = $month->addMonthsNoOverflow($offset);
            $day = $recurrence->is_last_day_of_month
                ? $monthStart->daysInMonth
                : min($dayOfMonth, $monthStart->daysInMonth);

            $candidate = $this->atTimeOfDay($recurrence, $monthStart->setDay($day), $timezone);

            if ($candidate->greaterThan($from)) {
                return $candidate;
            }
        }
    }

    private function nextCron(Recurrence $recurrence, CarbonImmutable $from, string $timezone): CarbonImmutable
    {
        $next = (new CronExpression((string) $recurrence->cron_expression))
            ->getNextRunDate($from->setTimezone($timezone)->toDateTime(), 0, false, $timezone);

        return CarbonImmutable::instance($next);
    }

    /**
     * The rule's wall-clock time on a local date, as an instant.
     */
    private function atTimeOfDay(Recurrence $recurrence, CarbonImmutable $date, string $timezone): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $recurrence->time_of_day ?? self::DEFAULT_TIME_OF_DAY));

        return CarbonImmutable::create($date->year, $date->month, $date->day, $hour, $minute, 0, $timezone);
    }
}
