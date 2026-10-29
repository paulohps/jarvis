<?php

namespace App\Services\Agenda;

use Carbon\CarbonImmutable;

/**
 * The timed entries of one day on the day and week grids, as Google
 * Calendar draws them: each at its start, as tall as it lasts (a moment as
 * MIN_MINUTES), and the ones that overlap side by side — every group of
 * overlapping entries split into as many lanes as it needs, each entry in
 * the first lane free at its start. An entry that runs past midnight is cut
 * at the end of the day.
 */
final class DayTimeline
{
    public const int MIN_MINUTES = 30;

    public const int DAY_MINUTES = 1440;

    /**
     * @param  list<CalendarEntry>  $entries  the day's timed entries
     * @return list<array{entry: CalendarEntry, top: int, height: int, lane: int, lanes: int}> `top` and `height` in minutes of the day
     */
    public static function layout(array $entries, string $day, string $timezone): array
    {
        $midnight = CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone);

        $placed = array_map(function (CalendarEntry $entry) use ($midnight): array {
            $top = max(0, (int) $midnight->diffInMinutes($entry->startsAt->setTimezone($midnight->getTimezone()), false));
            $top = min($top, self::DAY_MINUTES - self::MIN_MINUTES);

            return [
                'entry' => $entry,
                'top' => $top,
                'height' => min($entry->displayMinutes(self::MIN_MINUTES), self::DAY_MINUTES - $top),
                'lane' => 0,
                'lanes' => 1,
            ];
        }, $entries);

        usort($placed, fn (array $a, array $b): int => [$a['top'], -$a['height']] <=> [$b['top'], -$b['height']]);

        $laid = [];
        $group = [];
        $groupEnd = 0;
        $laneEnds = [];

        foreach ($placed as $item) {
            if ($group !== [] && $item['top'] >= $groupEnd) {
                array_push($laid, ...self::closeGroup($group, count($laneEnds)));
                $group = [];
                $laneEnds = [];
            }

            $lane = 0;

            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $item['top']) {
                $lane++;
            }

            $item['lane'] = $lane;
            $laneEnds[$lane] = $item['top'] + $item['height'];
            $groupEnd = max($group === [] ? 0 : $groupEnd, $item['top'] + $item['height']);
            $group[] = $item;
        }

        array_push($laid, ...self::closeGroup($group, count($laneEnds)));

        return $laid;
    }

    /**
     * @param  list<array{entry: CalendarEntry, top: int, height: int, lane: int, lanes: int}>  $group
     * @return list<array{entry: CalendarEntry, top: int, height: int, lane: int, lanes: int}>
     */
    private static function closeGroup(array $group, int $lanes): array
    {
        return array_map(fn (array $item): array => [...$item, 'lanes' => max(1, $lanes)], $group);
    }
}
