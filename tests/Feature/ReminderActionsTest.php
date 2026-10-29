<?php

use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\DeleteReminder;
use App\Actions\Reminders\UpdateReminder;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->creator = User::factory()->withModules([Module::REMINDERS])->create(['timezone' => 'America/Sao_Paulo']);
    $this->member = User::factory()->withModules([Module::REMINDERS])->create();
});

/**
 * The validation errors of a call, or an empty array when it passes.
 *
 * @return array<string, list<string>>
 */
function reminderErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('undated reminder creates one undated occurrence', function () {
    $reminder = app(CreateReminder::class)($this->creator, ['title' => '  Fix the faucet  ', 'note' => 'Kitchen']);

    expect($reminder->title)->toBe('Fix the faucet')
        ->and($reminder->note)->toBe('Kitchen')
        ->and($reminder->created_by_id)->toBe($this->creator->id)
        ->and($reminder->recurrence()->exists())->toBeFalse()
        ->and($reminder->occurrences()->count())->toBe(1)
        ->and($reminder->occurrences()->sole()->due_at)->toBeNull();

    // Shared in the household: the other member sees it as pending.
    expect(ReminderOccurrence::query()->pending()->whereRelation('reminder', 'id', $reminder->id)->count())->toBe(1);
});

test('dated reminder creates a once recurrence and occurrence', function () {
    // 09:00 in São Paulo is 12:00 UTC.
    $reminder = app(CreateReminder::class)($this->creator, ['title' => 'Dentist', 'due_at' => '2026-10-10T09:00']);

    $recurrence = $reminder->recurrence()->with('frequency')->sole();

    expect($recurrence->frequency->slug)->toBe(RecurrenceFrequency::ONCE)
        ->and($recurrence->next_run_at->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        ->and($reminder->occurrences()->count())->toBe(1)
        ->and($reminder->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        ->and($reminder->isOnce())->toBeTrue();

    // A past date is refused on the date field, and nothing is saved.
    expect(reminderErrors(fn () => app(CreateReminder::class)($this->creator, ['title' => 'Late', 'due_at' => '2026-10-01T09:00'])))
        ->toHaveKey('due_at')
        ->and(Reminder::query()->count())->toBe(1);

    // Moving the date moves the single open occurrence.
    app(UpdateReminder::class)($this->member, $reminder, ['title' => 'Dentist', 'due_at' => '2026-10-11T08:30']);

    expect($reminder->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-11T11:30:00+00:00')
        ->and($reminder->refresh()->created_by_id)->toBe($this->creator->id);

    // Once overdue, it can still be renamed with its date as it is.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00', 'UTC'));

    app(UpdateReminder::class)($this->member, $reminder, ['title' => 'Dentist (call)', 'due_at' => '2026-10-11T08:30']);

    expect($reminder->refresh()->title)->toBe('Dentist (call)')
        ->and($reminder->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-11T11:30:00+00:00');

    // Clearing the date makes it an undated task.
    app(UpdateReminder::class)($this->member, $reminder, ['title' => 'Dentist (call)', 'due_at' => null]);

    expect($reminder->recurrence()->exists())->toBeFalse()
        ->and($reminder->occurrences()->sole()->due_at)->toBeNull();
});

test('recurring reminder creates occurrence at next run', function () {
    $reminder = app(CreateReminder::class)($this->creator, [
        'title' => 'Water the plants',
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '08:00', 'weekdays' => ['wednesday']],
    ]);

    $recurrence = $reminder->recurrence()->sole();

    // Monday 2026-10-05: the next Wednesday at 08:00 São Paulo is 11:00 UTC.
    expect($recurrence->next_run_at->toIso8601String())->toBe('2026-10-07T11:00:00+00:00')
        ->and($reminder->isRecurring())->toBeTrue()
        ->and($reminder->occurrences()->count())->toBe(1)
        ->and($reminder->occurrences()->sole()->due_at->equalTo($recurrence->next_run_at))->toBeTrue();

    // The recurrence errors come back under `recurrence.*`.
    expect(reminderErrors(fn () => app(CreateReminder::class)($this->creator, [
        'title' => 'Broken',
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '08:00', 'weekdays' => []],
    ])))->toHaveKey('recurrence.weekdays');

    // An overdue run is kept when the rule changes; the upcoming one moves.
    $overdue = $reminder->occurrences()->create(['due_at' => '2026-09-30 11:00:00']);

    app(UpdateReminder::class)($this->member, $reminder, [
        'title' => 'Water the plants',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00'],
    ]);

    $open = $reminder->occurrences()->whereNull('completed_at')->orderBy('due_at')->pluck('due_at')->map->toIso8601String()->all();

    expect($open)->toBe([$overdue->due_at->toIso8601String(), '2026-10-05T21:00:00+00:00']);
});

test('title is required and limited', function () {
    expect(reminderErrors(fn () => app(CreateReminder::class)($this->creator, ['title' => '   '])))->toHaveKey('title')
        ->and(reminderErrors(fn () => app(CreateReminder::class)($this->creator, ['title' => str_repeat('a', 256)])))->toHaveKey('title')
        ->and(reminderErrors(fn () => app(CreateReminder::class)($this->creator, ['title' => str_repeat('a', 255)])))->toBe([]);
});

test('reminders need the module', function () {
    $outsider = User::factory()->create();

    expect(fn () => app(CreateReminder::class)($outsider, ['title' => 'Nope']))->toThrow(AuthorizationException::class);

    $reminder = app(CreateReminder::class)($this->creator, ['title' => 'Mine']);

    expect(fn () => app(UpdateReminder::class)($outsider, $reminder, ['title' => 'Theirs']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteReminder::class)($outsider, $reminder))->toThrow(AuthorizationException::class);
});

test('deleting reminder pauses its recurrence', function () {
    $reminder = app(CreateReminder::class)($this->creator, [
        'title' => 'Take out the trash',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '20:00'],
    ]);

    // Shared: another member may delete it.
    app(DeleteReminder::class)($this->member, $reminder);

    expect($reminder->refresh()->trashed())->toBeTrue()
        ->and($reminder->recurrence()->sole()->is_paused)->toBeTrue()
        ->and(ReminderOccurrence::query()->whereHas('reminder', fn ($query) => $query->withoutTrashed())->count())->toBe(0);

    // An undated task has no rule to pause.
    $task = app(CreateReminder::class)($this->creator, ['title' => 'Call grandma']);

    app(DeleteReminder::class)($this->creator, $task);

    expect($task->refresh()->trashed())->toBeTrue();
});
