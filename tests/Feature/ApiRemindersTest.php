<?php

use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\DeleteReminder;
use App\Livewire\Agenda\AgendaPage;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Models\Weekday;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::REMINDERS])->create(['timezone' => 'America/Sao_Paulo']);
    $this->member = User::factory()->withModules([Module::REMINDERS])->create();
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
});

/**
 * The friendly ids of the occurrences the web list shows under a filter.
 *
 * @return list<string>
 */
function webReminderFilter(User $user, string $filter): array
{
    return Livewire::actingAs($user)
        ->test(AgendaPage::class, ['view' => 'tasks', 'filter' => $filter])
        ->instance()
        ->occurrences
        ->pluck('friendly_id')
        ->all();
}

test('api reminder filters match web scopes', function () {
    $create = app(CreateReminder::class);

    $tomorrow = $create($this->user, ['title' => 'Pay the school', 'due_at' => '2026-10-06T09:00']);
    $undated = $create($this->member, ['title' => 'Buy bulbs']);
    $daily = $create($this->user, ['title' => 'Feed the cat', 'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00']]);
    $done = $create($this->member, ['title' => 'Call grandma', 'due_at' => '2026-10-07T10:00']);
    $done->occurrences()->sole()->update(['completed_at' => now(), 'completed_by_id' => $this->member->id]);
    $overdue = Reminder::factory()->for($this->user, 'createdBy')->create(['title' => 'Renew passport']);
    ReminderOccurrence::factory()->for($overdue)->overdue()->create();
    $deleted = $create($this->user, ['title' => 'Gone', 'due_at' => '2026-10-08T10:00']);
    app(DeleteReminder::class)($this->user, $deleted);

    $expected = [
        'pending' => webReminderFilter($this->user, 'pending'),
        'overdue' => webReminderFilter($this->user, 'overdue'),
        'completed' => webReminderFilter($this->user, 'completed'),
    ];

    expect($expected['pending'])->toHaveCount(3)
        ->and($expected['overdue'])->toHaveCount(1)
        ->and($expected['completed'])->toHaveCount(1);

    foreach ($expected as $filter => $occurrenceIds) {
        $this->withToken($this->token)
            ->getJson("/api/v1/reminders?filter={$filter}")
            ->assertOk()
            ->assertJsonPath('data.*.friendly_id', $occurrenceIds)
            ->assertJsonPath('meta.filter', $filter)
            ->assertJsonPath('meta.counts', ['pending' => 3, 'overdue' => 1, 'completed' => 1])
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo');
    }

    // Pending by default, in due order with the undated task last; dates in
    // ISO-8601 UTC.
    $this->withToken($this->token)
        ->getJson('/api/v1/reminders')
        ->assertOk()
        ->assertJsonPath('data.*.reminder.title', ['Feed the cat', 'Pay the school', 'Buy bulbs'])
        ->assertJsonPath('data.0.due_at', '2026-10-05T21:00:00+00:00')
        ->assertJsonPath('data.0.reminder.is_recurring', true)
        ->assertJsonPath('data.0.reminder.recurrence.frequency', 'daily')
        ->assertJsonPath('data.0.reminder.recurrence.time_of_day', '18:00')
        ->assertJsonPath('data.0.reminder.recurrence.timezone', 'America/Sao_Paulo')
        ->assertJsonPath('data.1.due_at', '2026-10-06T12:00:00+00:00')
        ->assertJsonPath('data.1.reminder.recurrence.frequency', 'once')
        ->assertJsonPath('data.2.due_at', null)
        ->assertJsonPath('data.2.reminder.recurrence', null)
        ->assertJsonPath('data.2.reminder.created_by.friendly_id', $this->member->friendly_id);

    $this->withToken($this->token)
        ->getJson('/api/v1/reminders?filter=overdue')
        ->assertJsonPath('data.0.is_overdue', true);

    $this->withToken($this->token)
        ->getJson('/api/v1/reminders?filter=completed')
        ->assertJsonPath('data.0.is_completed', true)
        ->assertJsonPath('data.0.completed_by.friendly_id', $this->member->friendly_id);

    $this->withToken($this->token)
        ->getJson('/api/v1/reminders?filter=everything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter']);
});

test('api complete creates next occurrence for recurring reminders', function () {
    $created = $this->withToken($this->token)
        ->postJson('/api/v1/reminders', [
            'title' => 'Feed the cat',
            'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Feed the cat')
        ->assertJsonPath('data.is_recurring', true)
        ->assertJsonPath('data.recurrence.next_run_at', '2026-10-05T21:00:00+00:00')
        ->assertJsonPath('data.open_occurrences.0.due_at', '2026-10-05T21:00:00+00:00')
        ->assertJsonPath('meta.timezone', 'America/Sao_Paulo');

    $first = $created->json('data.open_occurrences.0.friendly_id');

    $this->withToken($this->token)
        ->postJson("/api/v1/reminder-occurrences/{$first}/complete")
        ->assertOk()
        ->assertJsonPath('data.friendly_id', $first)
        ->assertJsonPath('data.is_completed', true)
        ->assertJsonPath('data.completed_at', '2026-10-05T12:00:00+00:00')
        ->assertJsonPath('data.completed_by.friendly_id', $this->user->friendly_id);

    $reminder = Reminder::query()->where('friendly_id', $created->json('data.friendly_id'))->sole();

    expect($reminder->occurrences()->orderBy('due_at')->pluck('due_at')->map->toIso8601String()->all())
        ->toBe(['2026-10-05T21:00:00+00:00', '2026-10-06T21:00:00+00:00']);

    // The next one is what is pending now.
    $this->withToken($this->token)
        ->getJson('/api/v1/reminders')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.due_at', '2026-10-06T21:00:00+00:00');

    // Reopening takes the completion back; the next occurrence stays.
    $this->withToken($this->token)
        ->postJson("/api/v1/reminder-occurrences/{$first}/reopen")
        ->assertOk()
        ->assertJsonPath('data.is_completed', false)
        ->assertJsonPath('data.completed_by', null);

    expect($reminder->occurrences()->count())->toBe(2);
});

test('api saves reminders with the same rules as the web', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/reminders', ['title' => '', 'due_at' => '2026-10-01T09:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);

    $this->withToken($this->token)
        ->postJson('/api/v1/reminders', ['title' => 'Too late', 'due_at' => '2026-10-01T09:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['due_at']);

    $this->withToken($this->token)
        ->postJson('/api/v1/reminders', ['title' => 'Bins', 'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '20:00']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence.weekdays']);

    expect(Reminder::query()->count())->toBe(0);

    // A date without an offset is read in the creator's timezone.
    $reminderId = $this->withToken($this->token)
        ->postJson('/api/v1/reminders', ['title' => 'Pay the school', 'note' => 'Bank slip', 'due_at' => '2026-10-06T09:00'])
        ->assertCreated()
        ->assertJsonPath('data.recurrence.starts_at', '2026-10-06T12:00:00+00:00')
        ->assertJsonPath('data.is_recurring', false)
        ->json('data.friendly_id');

    $this->withToken($this->token)
        ->patchJson("/api/v1/reminders/{$reminderId}", [
            'title' => 'Bins out',
            'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '20:00', 'weekdays' => [Weekday::MONDAY, Weekday::THURSDAY]],
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Bins out')
        ->assertJsonPath('data.note', null)
        ->assertJsonPath('data.recurrence.frequency', 'weekly')
        ->assertJsonPath('data.recurrence.weekdays', ['monday', 'thursday'])
        ->assertJsonCount(1, 'data.open_occurrences');

    $this->withToken($this->token)->deleteJson("/api/v1/reminders/{$reminderId}")->assertNoContent();
    $this->withToken($this->token)->deleteJson("/api/v1/reminders/{$reminderId}")->assertNotFound();

    expect(Reminder::withTrashed()->sole()->trashed())->toBeTrue();
});

test('api reads one reminder or event, which the agenda names by id', function () {
    $event = app(CreateReminder::class)($this->member, ['title' => 'Concert', 'kind' => 'event', 'due_at' => '2026-10-09T20:00', 'duration_minutes' => 120]);

    $this->withToken($this->token)->getJson("/api/v1/reminders/{$event->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Concert')
        ->assertJsonPath('data.kind', 'event')
        ->assertJsonPath('data.duration_minutes', 120)
        ->assertJsonPath('data.open_occurrences.0.friendly_id', $event->occurrences()->sole()->friendly_id)
        ->assertJsonPath('meta.timezone', 'America/Sao_Paulo')
        ->assertJsonCount(2, 'meta.assignees');

    $event->delete();

    $this->withToken($this->token)->getJson("/api/v1/reminders/{$event->friendly_id}")->assertNotFound();
});

test('api reminders require module', function () {
    $occurrence = app(CreateReminder::class)($this->user, ['title' => 'Pay the school'])->occurrences()->sole();
    $token = User::factory()->create()->createToken('Pixel')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/reminders')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/reminders/agenda')->assertForbidden();
    $this->withToken($token)->getJson("/api/v1/reminders/{$occurrence->reminder->friendly_id}")->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/reminders', ['title' => 'Mine'])->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/reminder-occurrences/{$occurrence->friendly_id}/complete")->assertForbidden();

    expect($occurrence->fresh()->completed_at)->toBeNull()
        ->and(Reminder::query()->count())->toBe(1);
});
