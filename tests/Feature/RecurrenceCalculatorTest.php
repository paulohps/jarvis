<?php

use App\Models\Recurrence;
use App\Models\Weekday;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->calculator = new RecurrenceCalculator;
});

function utcInstant(string $instant): CarbonImmutable
{
    return CarbonImmutable::parse($instant, 'UTC');
}

/**
 * A recurrence from the factory state, starting long before the instants
 * the tests look at.
 *
 * @param  Factory<Recurrence>  $factory
 * @param  array<string, mixed>  $attributes
 */
function calculatedRecurrence(Factory $factory, array $attributes = []): Recurrence
{
    return $factory->create([
        'timezone' => 'America/Sao_Paulo',
        'starts_at' => utcInstant('2026-01-01 00:00'),
        ...$attributes,
    ])->fresh();
}

/**
 * The ISO-8601 representation, to compare instants by value.
 */
function isoInstant(?CarbonImmutable $instant): ?string
{
    return $instant?->utc()->toIso8601String();
}

test('daily next run respects owner timezone', function () {
    $saoPaulo = calculatedRecurrence(Recurrence::factory()->daily(), ['time_of_day' => '09:00']);
    $newYork = calculatedRecurrence(Recurrence::factory()->daily(), ['time_of_day' => '09:00', 'timezone' => 'America/New_York']);

    expect(isoInstant($this->calculator->nextRunAfter($saoPaulo, utcInstant('2026-10-03 11:59'))))->toBe('2026-10-03T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($saoPaulo, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-04T12:00:00+00:00')
        // DST starts in New York on 2026-03-08: 09:00 local moves from 14:00 to 13:00 UTC.
        ->and(isoInstant($this->calculator->nextRunAfter($newYork, utcInstant('2026-03-06 15:00'))))->toBe('2026-03-07T14:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($newYork, utcInstant('2026-03-07 15:00'))))->toBe('2026-03-08T13:00:00+00:00')
        // DST ends on 2026-11-01: back to 14:00 UTC.
        ->and(isoInstant($this->calculator->nextRunAfter($newYork, utcInstant('2026-10-31 14:00'))))->toBe('2026-11-01T14:00:00+00:00')
        ->and($this->calculator->nextRunAfter($newYork, utcInstant('2026-10-31 14:00'))->timezone->getName())->toBe('UTC');
});

test('daily run at a time skipped by dst runs at the shifted time', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->daily(), ['time_of_day' => '02:30', 'timezone' => 'America/New_York']);

    // 02:30 does not exist in New York on 2026-03-08; it runs at 03:30 EDT (07:30 UTC).
    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-03-07 08:00'))))->toBe('2026-03-08T07:30:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-03-08 07:30'))))->toBe('2026-03-09T06:30:00+00:00');
});

test('weekly picks next selected weekday', function () {
    $recurrence = calculatedRecurrence(
        Recurrence::factory()->weekly([Weekday::MONDAY, Weekday::THURSDAY]),
        ['time_of_day' => '18:30'],
    );

    // 2026-10-03 is a Saturday; 18:30 in São Paulo is 21:30 UTC.
    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-05T21:30:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-05 21:00'))))->toBe('2026-10-05T21:30:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-05 21:30'))))->toBe('2026-10-08T21:30:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-08 21:30'))))->toBe('2026-10-12T21:30:00+00:00');
});

test('weekly with a single weekday runs on the same weekday next week', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->weekly([Weekday::SATURDAY]), ['time_of_day' => '09:00']);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-10T12:00:00+00:00');
});

test('monthly day 31 clamps to last day of shorter months', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->monthly(31), ['time_of_day' => '09:00']);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2027-01-31 12:00'))))->toBe('2027-02-28T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2027-02-28 12:00'))))->toBe('2027-03-31T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2027-03-31 12:00'))))->toBe('2027-04-30T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2028-01-31 12:00'))))->toBe('2028-02-29T12:00:00+00:00');
});

test('monthly day 15 runs on the 15th', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->monthly(15), ['time_of_day' => '09:00']);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-15T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-15 12:00'))))->toBe('2026-11-15T12:00:00+00:00');
});

test('monthly last day flag picks last day', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->lastDayOfMonth(), ['time_of_day' => '09:00']);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-31T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-31 12:00'))))->toBe('2026-11-30T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2027-01-31 12:00'))))->toBe('2027-02-28T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2028-02-01 12:00'))))->toBe('2028-02-29T12:00:00+00:00');
});

test('once returns null after its run', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->once(), ['starts_at' => utcInstant('2026-10-10 15:00')]);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-10T15:00:00+00:00')
        ->and($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-10 15:00')))->toBeNull()
        ->and($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-11 00:00')))->toBeNull();
});

test('cron expression is honoured', function () {
    $weekdayMornings = calculatedRecurrence(Recurrence::factory()->cron('30 8 * * 1-5'));
    $hourly = calculatedRecurrence(Recurrence::factory()->cron('0 * * * *'));
    $newYork = calculatedRecurrence(Recurrence::factory()->cron('0 9 * * *'), ['timezone' => 'America/New_York']);

    // Saturday → Monday 08:30 in São Paulo (11:30 UTC).
    expect(isoInstant($this->calculator->nextRunAfter($weekdayMornings, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-05T11:30:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($weekdayMornings, utcInstant('2026-10-05 11:30'))))->toBe('2026-10-06T11:30:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($hourly, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-03T13:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($hourly, utcInstant('2026-10-03 12:59:30'))))->toBe('2026-10-03T13:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($newYork, utcInstant('2026-03-07 15:00'))))->toBe('2026-03-08T13:00:00+00:00');
});

test('no next run after ends at', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->daily(), [
        'time_of_day' => '09:00',
        'ends_at' => utcInstant('2026-10-05 12:00'),
    ]);
    $cron = calculatedRecurrence(Recurrence::factory()->cron('0 * * * *'), ['ends_at' => utcInstant('2026-10-05 12:30')]);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-04 13:00'))))->toBe('2026-10-05T12:00:00+00:00')
        ->and($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-05 12:00')))->toBeNull()
        ->and($this->calculator->nextRunAfter($recurrence, utcInstant('2026-12-01 00:00')))->toBeNull()
        ->and($this->calculator->nextRunAfter($cron, utcInstant('2026-10-05 12:00')))->toBeNull();
});

test('no run happens before starts at', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->daily(), [
        'time_of_day' => '09:00',
        'starts_at' => utcInstant('2026-10-10 12:00'),
    ]);
    $cron = calculatedRecurrence(Recurrence::factory()->cron('0 * * * *'), ['starts_at' => utcInstant('2026-10-10 12:00')]);

    expect(isoInstant($this->calculator->nextRunAfter($recurrence, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-10T12:00:00+00:00')
        ->and(isoInstant($this->calculator->nextRunAfter($cron, utcInstant('2026-10-03 12:00'))))->toBe('2026-10-10T12:00:00+00:00');
});

test('next runs lists the upcoming runs until the end', function () {
    $recurrence = calculatedRecurrence(Recurrence::factory()->daily(), [
        'time_of_day' => '09:00',
        'ends_at' => utcInstant('2026-10-05 12:00'),
    ]);

    $runs = $this->calculator->nextRuns($recurrence, utcInstant('2026-10-03 13:00'), 3);

    expect(array_map(isoInstant(...), $runs))->toBe(['2026-10-04T12:00:00+00:00', '2026-10-05T12:00:00+00:00']);
});
