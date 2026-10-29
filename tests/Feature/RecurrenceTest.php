<?php

use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Weekday;
use App\Support\FriendlyId;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->owner = User::factory()->create();
});

/**
 * A reminder owning a daily recurrence with the given attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function reminderRecurrence(User $owner, array $attributes = []): Recurrence
{
    $reminder = (new Reminder)->forceFill([
        'friendly_id' => FriendlyId::generate('rem'),
        'workspace_id' => WorkspaceFactory::household()->id,
        'title' => fake()->sentence(3),
        'created_by_id' => $owner->id,
    ]);
    $reminder->save();

    return $reminder->recurrence()->create([
        'recurrence_frequency_id' => RecurrenceFrequency::idFor(RecurrenceFrequency::DAILY),
        'time_of_day' => '09:00',
        'timezone' => 'America/Sao_Paulo',
        'starts_at' => now()->subWeek(),
        'next_run_at' => now()->subMinute(),
        ...$attributes,
    ]);
}

test('due scope skips paused and future recurrences', function () {
    $due = reminderRecurrence($this->owner);
    $dueNow = reminderRecurrence($this->owner, ['next_run_at' => now()]);
    reminderRecurrence($this->owner, ['is_paused' => true]);
    reminderRecurrence($this->owner, ['next_run_at' => now()->addMinute()]);
    reminderRecurrence($this->owner, ['next_run_at' => null]);

    expect(Recurrence::query()->due(now())->pluck('id')->all())->toEqualCanonicalizing([$due->id, $dueNow->id])
        ->and(Recurrence::query()->due(now()->addHour())->count())->toBe(3)
        ->and(Recurrence::query()->due()->count())->toBe(2);
});

test('due scope skips soft deleted owners', function () {
    $active = reminderRecurrence($this->owner);
    $orphaned = reminderRecurrence($this->owner);

    $orphaned->schedulable->delete();

    expect(Reminder::withTrashed()->find($orphaned->schedulable_id)->trashed())->toBeTrue()
        ->and(Recurrence::query()->due(now())->pluck('id')->all())->toBe([$active->id]);
});

test('recurrence relations and casts', function () {
    $recurrence = reminderRecurrence($this->owner, [
        'recurrence_frequency_id' => RecurrenceFrequency::idFor(RecurrenceFrequency::WEEKLY),
        'time_of_day' => '18:30',
    ]);
    $recurrence->weekdays()->attach([Weekday::idFor(Weekday::MONDAY), Weekday::idFor(Weekday::FRIDAY)]);

    $recurrence = $recurrence->fresh();

    expect($recurrence->schedulable)->toBeInstanceOf(Reminder::class)
        ->and($recurrence->schedulable->recurrence->is($recurrence))->toBeTrue()
        ->and($recurrence->schedulable_type)->toBe('reminder')
        ->and($recurrence->frequency->slug)->toBe(RecurrenceFrequency::WEEKLY)
        ->and($recurrence->weekdays->pluck('id')->all())->toEqualCanonicalizing([1, 5])
        ->and(Weekday::bySlug(Weekday::MONDAY)->recurrences->first()->is($recurrence))->toBeTrue()
        ->and($recurrence->time_of_day)->toBe('18:30')
        ->and($recurrence->is_paused)->toBeFalse()
        ->and($recurrence->is_last_day_of_month)->toBeFalse()
        ->and($recurrence->starts_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($recurrence->next_run_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($recurrence->ends_at)->toBeNull();
});

test('time of day rejects an invalid time', function () {
    $recurrence = new Recurrence;
    $recurrence->time_of_day = '25:00';
})->throws(InvalidArgumentException::class);
