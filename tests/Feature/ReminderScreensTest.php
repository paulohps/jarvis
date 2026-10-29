<?php

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Livewire\Agenda\AgendaPage;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::REMINDERS])->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
    $this->member = User::factory()->withModules([Module::REMINDERS])->create(['name' => 'Ana']);
});

test('agenda page needs the module, and the old address leads to it', function () {
    $this->actingAs($this->user)->get(route('reminders.index'))->assertOk()->assertSee('Agenda');
    $this->actingAs($this->user)->get('/reminders')->assertRedirect('/agenda');
    $this->actingAs(User::factory()->create())->get(route('reminders.index'))->assertForbidden();
});

test('each occurrence appears in exactly one filter', function () {
    $undated = app(CreateReminder::class)($this->user, ['title' => 'Undated task'])->occurrences()->sole();
    $upcoming = app(CreateReminder::class)($this->user, ['title' => 'Upcoming', 'due_at' => '2026-10-06T09:00'])->occurrences()->sole();
    $overdue = ReminderOccurrence::factory()->overdue()->for(Reminder::factory()->for($this->member, 'createdBy'))->create();
    $completed = app(CreateReminder::class)($this->member, ['title' => 'Done'])->occurrences()->sole();
    app(CompleteReminderOccurrence::class)($this->member, $completed);

    $deleted = app(CreateReminder::class)($this->user, ['title' => 'Deleted one']);
    $deleted->delete();

    $shown = fn (string $filter): array => Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks', 'filter' => $filter])
        ->get('occurrences')
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($shown('pending'))->toBe(collect([$undated->id, $upcoming->id])->sort()->values()->all())
        ->and($shown('overdue'))->toBe([$overdue->id])
        ->and($shown('completed'))->toBe([$completed->id]);

    // Shared in the household: the other member sees the same lists.
    Livewire::actingAs($this->member)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->assertSee('Undated task')
        ->assertSee('Upcoming')
        ->assertDontSee('Deleted one')
        // The date is shown in the reader's timezone (São Paulo for Paulo; UTC for Ana).
        ->assertSee('10/06/2026 12:00');

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->assertSee('10/06/2026 09:00')
        ->set('filter', 'completed')
        ->assertSee('Done')
        ->assertSee('Completed by Ana');
});

test('checkbox completes and reopens in place', function () {
    $occurrence = app(CreateReminder::class)($this->member, ['title' => 'Water the plants'])->occurrences()->sole();

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->assertSeeHtml('data-reminder-toggle="'.$occurrence->friendly_id.'"')
        ->call('toggle', $occurrence->friendly_id)
        ->assertNoRedirect()
        ->assertDontSee('Water the plants')
        ->set('filter', 'completed')
        ->assertSee('Water the plants')
        ->assertSee('Completed by Paulo')
        ->assertSeeHtml('aria-checked="true"');

    expect($occurrence->refresh()->completed_by_id)->toBe($this->user->id);

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks', 'filter' => 'completed'])
        ->call('toggle', $occurrence->friendly_id)
        ->assertDontSee('Water the plants');

    expect($occurrence->refresh()->completed_at)->toBeNull();
});

test('form creates, edits and deletes reminders', function () {
    $component = Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->call('create')
        ->assertSet('showForm', true)
        ->call('save')
        ->assertHasErrors('title')
        ->set('title', 'Dentist')
        ->set('hasDate', true)
        ->assertSet('due_at', '2026-10-06T09:00')
        ->set('due_at', '2026-10-10T08:30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSee('Dentist');

    $reminder = Reminder::query()->where('title', 'Dentist')->sole();

    expect($reminder->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-10T11:30:00+00:00');

    // Editing loads the date and switches to a repeat.
    $component->call('edit', $reminder->friendly_id)
        ->assertSet('hasDate', true)
        ->assertSet('due_at', '2026-10-10T08:30')
        ->set('repeats', true)
        ->set('recurrence.frequency', RecurrenceFrequency::DAILY)
        ->set('recurrence.time_of_day', '07:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-reminder-repeat');

    expect($reminder->refresh()->isRecurring())->toBeTrue()
        ->and($reminder->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-06T10:00:00+00:00');

    $component->call('edit', $reminder->friendly_id)
        ->assertSet('repeats', true)
        ->assertSet('recurrence.time_of_day', '07:00')
        ->call('delete', $reminder->friendly_id)
        ->assertSee('Reminder “Dentist” deleted.')
        ->assertDontSeeHtml('data-reminder-edit="'.$reminder->friendly_id.'"');

    expect($reminder->refresh()->trashed())->toBeTrue()
        ->and($reminder->recurrence->is_paused)->toBeTrue();
});

test('a past date is refused on the form', function () {
    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->call('create')
        ->set('title', 'Too late')
        ->set('hasDate', true)
        ->set('due_at', '2026-10-01T09:00')
        ->call('save')
        ->assertHasErrors('due_at');

    expect(Reminder::query()->count())->toBe(0);
});
