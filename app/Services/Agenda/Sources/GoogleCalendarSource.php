<?php

namespace App\Services\Agenda\Sources;

use App\Models\GoogleCalendar;
use App\Models\Module;
use App\Models\User;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\CalendarSource;
use App\Services\Agenda\Google\GoogleCalendarClient;
use App\Services\Agenda\Google\GoogleCalendarException;
use Carbon\CarbonImmutable;

/**
 * The events of the reader's own Google calendars — the shown ones of the
 * accounts still working — read only. An all-day event shows on each of
 * its days; a timed one at its start, lasting until its end. A calendar
 * Google cannot be read for leaves its events out instead of failing the
 * agenda.
 */
final readonly class GoogleCalendarSource implements CalendarSource
{
    /**
     * The most days an all-day event is repeated on.
     */
    private const int MAX_ALL_DAY_SPAN = 62;

    public function __construct(private GoogleCalendarClient $client) {}

    public function keys(): array
    {
        return [CalendarFeed::GOOGLE];
    }

    public function isAvailableTo(User $reader): bool
    {
        return $reader->hasModule(Module::REMINDERS)
            && GoogleCalendar::query()->whereRelation('account', 'user_id', $reader->id)->exists();
    }

    public function entries(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee): array
    {
        $calendars = GoogleCalendar::query()
            ->where('is_visible', true)
            ->whereHas('account', fn ($query) => $query->where('user_id', $reader->id)->whereNull('failed_at'))
            ->with('account')
            ->get();

        $timezone = $reader->timezone;
        $firstDay = $from->setTimezone($timezone)->format('Y-m-d');
        $lastDay = $to->setTimezone($timezone)->subSecond()->format('Y-m-d');
        $entries = [];

        foreach ($calendars as $calendar) {
            try {
                $events = $this->client->events($calendar, $from, $to);
            } catch (GoogleCalendarException $exception) {
                report($exception);

                continue;
            }

            foreach ($events as $event) {
                $key = "{$calendar->friendly_id}:{$event->id}";

                if (! $event->isAllDay()) {
                    if ($event->startsAt !== null && $event->startsAt->greaterThanOrEqualTo($from) && $event->startsAt->lessThan($to)) {
                        $entries[] = new CalendarEntry(
                            key: $key,
                            source: CalendarFeed::GOOGLE,
                            title: $event->title,
                            startsAt: $event->startsAt,
                            endsAt: $event->endsAt,
                            subtitle: $calendar->name,
                            url: $event->url,
                        );
                    }

                    continue;
                }

                $day = CarbonImmutable::createFromFormat('!Y-m-d', (string) $event->startDate, $timezone);
                $end = $event->endDate === null ? $day->addDay() : CarbonImmutable::createFromFormat('!Y-m-d', $event->endDate, $timezone);

                for ($span = 0; $day->lessThan($end) && $span < self::MAX_ALL_DAY_SPAN; $span++, $day = $day->addDay()) {
                    $date = $day->format('Y-m-d');

                    if ($date < $firstDay || $date > $lastDay) {
                        continue;
                    }

                    $entries[] = new CalendarEntry(
                        key: "{$key}#{$date}",
                        source: CalendarFeed::GOOGLE,
                        title: $event->title,
                        startsAt: $day->setTime(12, 0)->utc(),
                        isAllDay: true,
                        subtitle: $calendar->name,
                        url: $event->url,
                    );
                }
            }
        }

        return $entries;
    }
}
