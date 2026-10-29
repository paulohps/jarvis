<?php

use App\Actions\Recurrences\PauseRecurrence;
use App\Actions\Recurrences\ResumeRecurrence;
use App\Actions\Recurrences\SaveRecurrence;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Weekday;
use Database\Seeders\LookupSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 3)->setTime(12, 0));
    $this->owner = Reminder::factory()->for(User::factory()->state(['timezone' => 'America/Sao_Paulo']), 'createdBy')->create();
});

/**
 * The validation errors `SaveRecurrence` throws for the data, keyed by field.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function recurrenceErrors(Reminder $owner, array $data): array
{
    try {
        app(SaveRecurrence::class)($owner, $data);
    } catch (ValidationException $exception) {
        expect($exception->status)->toBe(422);

        return $exception->errors();
    }

    throw new RuntimeException('The recurrence was saved.');
}

test('saving a weekly recurrence stores its weekdays, owner timezone and next run', function () {
    $recurrence = app(SaveRecurrence::class)($this->owner, [
        'frequency' => RecurrenceFrequency::WEEKLY,
        'time_of_day' => '18:30',
        'weekdays' => [Weekday::MONDAY, Weekday::THURSDAY],
        'day_of_month' => 12,
        'cron_expression' => '* * * * *',
    ])->fresh();

    expect($recurrence->schedulable->is($this->owner))->toBeTrue()
        ->and($recurrence->frequency->slug)->toBe(RecurrenceFrequency::WEEKLY)
        ->and($recurrence->timezone)->toBe('America/Sao_Paulo')
        ->and($recurrence->weekdays->pluck('slug')->all())->toEqualCanonicalizing([Weekday::MONDAY, Weekday::THURSDAY])
        ->and($recurrence->day_of_month)->toBeNull()
        ->and($recurrence->cron_expression)->toBeNull()
        ->and($recurrence->starts_at->toIso8601String())->toBe('2026-10-03T12:00:00+00:00')
        ->and($recurrence->next_run_at->toIso8601String())->toBe('2026-10-05T21:30:00+00:00');
});

test('fields of other presets are ignored', function () {
    $recurrence = app(SaveRecurrence::class)($this->owner, [
        'frequency' => RecurrenceFrequency::DAILY,
        'time_of_day' => '09:00',
        'weekdays' => [],
        'day_of_month' => null,
        'cron_expression' => '',
    ])->fresh();

    expect($recurrence->frequency->slug)->toBe(RecurrenceFrequency::DAILY)
        ->and($recurrence->weekdays)->toBeEmpty()
        ->and($recurrence->next_run_at->toIso8601String())->toBe('2026-10-04T12:00:00+00:00');
});

test('saving again replaces the owner recurrence', function () {
    $first = app(SaveRecurrence::class)($this->owner, [
        'frequency' => RecurrenceFrequency::WEEKLY,
        'time_of_day' => '09:00',
        'weekdays' => [Weekday::MONDAY],
    ]);

    $second = app(SaveRecurrence::class)($this->owner, [
        'frequency' => RecurrenceFrequency::MONTHLY,
        'time_of_day' => '09:00',
        'is_last_day_of_month' => true,
        'starts_at' => '2026-10-01T00:00',
        'ends_at' => '2027-01-01T00:00',
    ])->fresh();

    expect($second->id)->toBe($first->id)
        ->and(Recurrence::query()->count())->toBe(1)
        ->and($second->weekdays)->toBeEmpty()
        ->and($second->is_last_day_of_month)->toBeTrue()
        ->and($second->day_of_month)->toBeNull()
        ->and($second->starts_at->toIso8601String())->toBe('2026-10-01T03:00:00+00:00')
        ->and($second->ends_at->toIso8601String())->toBe('2027-01-01T03:00:00+00:00')
        ->and($second->next_run_at->toIso8601String())->toBe('2026-10-31T12:00:00+00:00');
});

test('a once recurrence runs at its local start', function () {
    $recurrence = app(SaveRecurrence::class)($this->owner, [
        'frequency' => RecurrenceFrequency::ONCE,
        'time_of_day' => '09:00',
        'starts_at' => '2026-10-10T09:00',
    ])->fresh();

    expect($recurrence->time_of_day)->toBeNull()
        ->and($recurrence->starts_at->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        ->and($recurrence->next_run_at->toIso8601String())->toBe('2026-10-10T12:00:00+00:00');
});

test('weekly recurrence requires at least one weekday', function () {
    expect(recurrenceErrors($this->owner, ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '09:00', 'weekdays' => []]))
        ->toHaveKey('weekdays')
        ->and(recurrenceErrors($this->owner, ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '09:00']))
        ->toHaveKey('weekdays')
        ->and(recurrenceErrors($this->owner, ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '09:00', 'weekdays' => ['someday']]))
        ->toHaveKey('weekdays.0')
        ->and(Recurrence::query()->count())->toBe(0);
});

test('invalid cron expression is rejected', function () {
    expect(recurrenceErrors($this->owner, ['frequency' => RecurrenceFrequency::CUSTOM_CRON, 'cron_expression' => '61 * * * *']))
        ->toHaveKey('cron_expression')
        ->and(recurrenceErrors($this->owner, ['frequency' => RecurrenceFrequency::CUSTOM_CRON, 'cron_expression' => 'every day']))
        ->toHaveKey('cron_expression')
        ->and(recurrenceErrors($this->owner, ['frequency' => RecurrenceFrequency::CUSTOM_CRON]))
        ->toHaveKey('cron_expression')
        ->and(Recurrence::query()->count())->toBe(0);
});

test('the other preset rules are validated', function (array $data, string $field) {
    expect(recurrenceErrors($this->owner, $data))->toHaveKey($field);
})->with([
    'unknown preset' => [['frequency' => 'hourly'], 'frequency'],
    'daily without time' => [['frequency' => RecurrenceFrequency::DAILY], 'time_of_day'],
    'invalid time' => [['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '25:00'], 'time_of_day'],
    'monthly without day' => [['frequency' => RecurrenceFrequency::MONTHLY, 'time_of_day' => '09:00'], 'day_of_month'],
    'monthly day 0' => [['frequency' => RecurrenceFrequency::MONTHLY, 'time_of_day' => '09:00', 'day_of_month' => 0], 'day_of_month'],
    'monthly day 32' => [['frequency' => RecurrenceFrequency::MONTHLY, 'time_of_day' => '09:00', 'day_of_month' => 32], 'day_of_month'],
    'once without start' => [['frequency' => RecurrenceFrequency::ONCE], 'starts_at'],
    'once in the past' => [['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-03T08:00'], 'starts_at'],
    'end before start' => [['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '09:00', 'starts_at' => '2026-10-10T00:00', 'ends_at' => '2026-10-09T00:00'], 'ends_at'],
    'end equal to start' => [['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '09:00', 'starts_at' => '2026-10-10T00:00', 'ends_at' => '2026-10-10T00:00'], 'ends_at'],
]);

test('the owner timezone must be valid', function () {
    $this->owner->createdBy->forceFill(['timezone' => 'Mars/Olympus'])->save();

    expect(recurrenceErrors($this->owner->fresh(), ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '09:00']))
        ->toHaveKey('timezone');
});

test('pausing stops the recurrence from being due', function () {
    $recurrence = app(SaveRecurrence::class)($this->owner, ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '09:00']);

    app(PauseRecurrence::class)($recurrence);
    $this->travel(2)->days();

    expect($recurrence->fresh()->is_paused)->toBeTrue()
        ->and(Recurrence::query()->due()->count())->toBe(0);
});

test('resuming recalculates from now without backfilling', function () {
    $recurrence = app(SaveRecurrence::class)($this->owner, ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '09:00']);
    expect($recurrence->next_run_at->toIso8601String())->toBe('2026-10-04T12:00:00+00:00');

    app(PauseRecurrence::class)($recurrence);
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(15, 0));

    $recurrence = app(ResumeRecurrence::class)($recurrence->fresh())->fresh();

    expect($recurrence->is_paused)->toBeFalse()
        ->and($recurrence->next_run_at->toIso8601String())->toBe('2026-10-11T12:00:00+00:00')
        ->and(Recurrence::query()->due()->count())->toBe(0);
});
