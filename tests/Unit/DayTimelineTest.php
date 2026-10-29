<?php

use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\DayTimeline;
use Carbon\CarbonImmutable;

function timelineEntry(string $key, string $start, ?string $end = null): CalendarEntry
{
    return new CalendarEntry(
        key: $key,
        source: 'events',
        title: $key,
        startsAt: CarbonImmutable::parse($start, 'America/Sao_Paulo')->utc(),
        endsAt: $end === null ? null : CarbonImmutable::parse($end, 'America/Sao_Paulo')->utc(),
    );
}

/**
 * @return array<string, array{top: int, height: int, lane: int, lanes: int}>
 */
function laidOut(array $entries): array
{
    return collect(DayTimeline::layout($entries, '2026-10-06', 'America/Sao_Paulo'))
        ->mapWithKeys(fn (array $item): array => [$item['entry']->key => [
            'top' => $item['top'],
            'height' => $item['height'],
            'lane' => $item['lane'],
            'lanes' => $item['lanes'],
        ]])
        ->all();
}

test('overlapping entries share the width in lanes, the others take all of it', function () {
    expect(laidOut([
        timelineEntry('meeting', '2026-10-06 09:00', '2026-10-06 10:30'),
        timelineEntry('call', '2026-10-06 09:30', '2026-10-06 10:00'),
        timelineEntry('coffee', '2026-10-06 10:00'),
        timelineEntry('lunch', '2026-10-06 12:00', '2026-10-06 13:00'),
    ]))->toBe([
        'meeting' => ['top' => 540, 'height' => 90, 'lane' => 0, 'lanes' => 2],
        'call' => ['top' => 570, 'height' => 30, 'lane' => 1, 'lanes' => 2],
        // The call's lane is free again at 10:00.
        'coffee' => ['top' => 600, 'height' => 30, 'lane' => 1, 'lanes' => 2],
        'lunch' => ['top' => 720, 'height' => 60, 'lane' => 0, 'lanes' => 1],
    ]);
});

test('an entry running past midnight is cut at the end of the day', function () {
    expect(laidOut([timelineEntry('party', '2026-10-06 23:00', '2026-10-07 02:00')]))
        ->toBe(['party' => ['top' => 1380, 'height' => 60, 'lane' => 0, 'lanes' => 1]]);
});
