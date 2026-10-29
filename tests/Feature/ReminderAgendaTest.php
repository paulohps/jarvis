<?php

use App\Actions\Reminders\CreateReminder;
use App\Livewire\Agenda\AgendaPage;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Services\Reminders\AgendaEntry;
use App\Services\Reminders\ReminderOccurrenceFeed;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    // 09:00 on Monday in São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::REMINDERS])->create(['timezone' => 'America/Sao_Paulo']);
    $this->member = User::factory()->withModules([Module::REMINDERS])->create();
});

test('the agenda lays open occurrences out by day, overdue first and undated last', function () {
    $create = app(CreateReminder::class);
    $create($this->user, ['title' => 'Feed the cat', 'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00']]);
    $create($this->user, ['title' => 'Dentist', 'due_at' => '2026-12-01T10:00']);
    $create($this->user, ['title' => 'Fix the shelf']);
    $done = $create($this->user, ['title' => 'Already done', 'due_at' => '2026-10-06T10:00']);
    $done->occurrences()->sole()->update(['completed_at' => now(), 'completed_by_id' => $this->user->id]);
    ReminderOccurrence::factory()->overdue()->for(Reminder::factory()->for($this->user, 'createdBy')->state(['title' => 'Renew passport']))->create();

    $agenda = app(ReminderOccurrenceFeed::class)->agenda($this->user);
    $titles = fn (string $group): array => array_map(fn (AgendaEntry $entry): string => $entry->reminder->title, $agenda[$group]);

    // The daily one: its occurrence today, then its runs projected for the next 14 days.
    $days = array_map(fn (int $day): string => CarbonImmutable::parse('2026-10-05')->addDays($day)->format('Y-m-d'), range(0, 14));

    expect(array_keys($agenda))->toBe(['overdue', ...$days, '2026-12-01', 'undated'])
        ->and($titles('overdue'))->toBe(['Renew passport'])
        ->and($titles('2026-10-05'))->toBe(['Feed the cat'])
        ->and($agenda['2026-10-05'][0]->isProjected())->toBeFalse()
        ->and($titles('2026-10-06'))->toBe(['Feed the cat'])
        ->and($agenda['2026-10-06'][0]->isProjected())->toBeTrue()
        ->and($agenda['2026-10-19'][0]->dueAt->toIso8601String())->toBe('2026-10-19T21:00:00+00:00')
        // A one-off date beyond the projection is still on the agenda.
        ->and($titles('2026-12-01'))->toBe(['Dentist'])
        ->and($titles('undated'))->toBe(['Fix the shelf']);
});

test('the schedule view names the days and leaves projected runs without a checkbox', function () {
    $reminder = app(CreateReminder::class)($this->user, ['title' => 'Feed the cat', 'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00']]);
    $occurrence = $reminder->occurrences()->sole();
    app(CreateReminder::class)($this->member, ['title' => 'Ana\'s errand', 'due_at' => '2026-10-06T10:00']);

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'schedule'])
        ->assertDontSeeHtml('data-reminder-filter')
        ->assertSeeInOrder(['Today', 'Feed the cat', 'Tomorrow', 'Ana\'s errand', 'Feed the cat', 'Wednesday, October 7, 2026'])
        ->assertSeeHtml('data-reminder-toggle="'.$occurrence->friendly_id.'"')
        ->assertSeeHtml('data-agenda-entry="'.$reminder->friendly_id.'@'.CarbonImmutable::parse('2026-10-06 21:00', 'UTC')->getTimestamp().'"')
        ->set('mine', true)
        ->assertDontSee('Ana\'s errand')
        ->set('view', 'tasks')
        ->assertSeeHtml('data-reminder-filter');
});

test('the api gives the same agenda, projected runs without an occurrence', function () {
    $reminder = app(CreateReminder::class)($this->user, ['title' => 'Feed the cat', 'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00']]);
    app(CreateReminder::class)($this->user, ['title' => 'Ana\'s errand', 'assigned_to' => $this->member->friendly_id]);
    $token = $this->user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/reminders/agenda?mine=1')
        ->assertOk()
        ->assertJsonPath('data.0.key', '2026-10-05')
        ->assertJsonPath('data.0.date', '2026-10-05')
        ->assertJsonPath('data.0.entries.0.is_projected', false)
        ->assertJsonPath('data.0.entries.0.occurrence.friendly_id', $reminder->occurrences()->sole()->friendly_id)
        ->assertJsonPath('data.1.entries.0.is_projected', true)
        ->assertJsonPath('data.1.entries.0.occurrence', null)
        ->assertJsonPath('data.1.entries.0.due_at', '2026-10-06T21:00:00+00:00')
        ->assertJsonPath('data.1.entries.0.reminder.friendly_id', $reminder->friendly_id)
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.mine', true)
        ->assertJsonPath('meta.projection_days', 14);

    // Everyone's: the undated errand comes last.
    $this->withToken($token)->getJson('/api/v1/reminders/agenda')
        ->assertJsonCount(16, 'data')
        ->assertJsonPath('data.15.key', 'undated')
        ->assertJsonPath('data.15.date', null)
        ->assertJsonPath('data.15.entries.0.reminder.assigned_to.name', $this->member->name);
});
