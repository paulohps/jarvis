<?php

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\DeleteReminder;
use App\Actions\Reminders\ReopenReminderOccurrence;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
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

test('completing records who and when', function () {
    $reminder = app(CreateReminder::class)($this->creator, ['title' => 'Pay the school', 'due_at' => '2026-10-06T09:00']);
    $occurrence = $reminder->occurrences()->sole();

    // Shared: anyone with the module completes it.
    app(CompleteReminderOccurrence::class)($this->member, $occurrence);

    expect($occurrence->refresh()->completed_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00')
        ->and($occurrence->completed_by_id)->toBe($this->member->id)
        ->and($occurrence->completedBy->is($this->member))->toBeTrue()
        // A `once` reminder has nothing next.
        ->and($reminder->occurrences()->count())->toBe(1);

    // Completing again keeps the first completion.
    $this->travel(1)->hour();
    app(CompleteReminderOccurrence::class)($this->creator, $occurrence);

    expect($occurrence->refresh()->completed_by_id)->toBe($this->member->id)
        ->and($occurrence->completed_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');

    // An undated task completes the same way.
    $task = app(CreateReminder::class)($this->creator, ['title' => 'Buy bulbs'])->occurrences()->sole();
    app(CompleteReminderOccurrence::class)($this->creator, $task);

    expect($task->refresh()->completed_by_id)->toBe($this->creator->id);

    // Not without the module.
    expect(fn () => app(CompleteReminderOccurrence::class)(User::factory()->create(), $task))->toThrow(AuthorizationException::class);
});

test('completing a recurring reminder creates the next occurrence once', function () {
    $reminder = app(CreateReminder::class)($this->creator, [
        'title' => 'Feed the cat',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00'],
    ]);
    $first = $reminder->occurrences()->sole();

    // Due today at 18:00 São Paulo (21:00 UTC), completed ahead of time.
    expect($first->due_at->toIso8601String())->toBe('2026-10-05T21:00:00+00:00');

    app(CompleteReminderOccurrence::class)($this->creator, $first);
    app(CompleteReminderOccurrence::class)($this->member, $first);

    $dueDates = $reminder->occurrences()->orderBy('due_at')->pluck('due_at')->map->toIso8601String()->all();

    expect($dueDates)->toBe(['2026-10-05T21:00:00+00:00', '2026-10-06T21:00:00+00:00'])
        ->and($reminder->occurrences()->whereNull('completed_at')->count())->toBe(1);

    // Completing an overdue run whose next one already exists adds nothing.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 22:00', 'UTC'));
    $second = $reminder->occurrences()->whereNull('completed_at')->sole();

    app(CompleteReminderOccurrence::class)($this->creator, $second);

    expect($reminder->occurrences()->orderBy('due_at')->pluck('due_at')->map->toIso8601String()->all())
        ->toBe(['2026-10-05T21:00:00+00:00', '2026-10-06T21:00:00+00:00', '2026-10-07T21:00:00+00:00']);
});

test('reopening clears completion', function () {
    $reminder = app(CreateReminder::class)($this->creator, ['title' => 'Renew passport']);
    $occurrence = $reminder->occurrences()->sole();

    app(CompleteReminderOccurrence::class)($this->creator, $occurrence);
    app(ReopenReminderOccurrence::class)($this->member, $occurrence);

    expect($occurrence->refresh()->completed_at)->toBeNull()
        ->and($occurrence->completed_by_id)->toBeNull();

    // The occurrences of a deleted reminder stay as they are.
    app(DeleteReminder::class)($this->creator, $reminder);

    expect(fn () => app(CompleteReminderOccurrence::class)($this->creator, $occurrence->refresh()))->toThrow(ValidationException::class)
        ->and(fn () => app(ReopenReminderOccurrence::class)($this->creator, $occurrence))->toThrow(ValidationException::class);
});
