<?php

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\UpdateReminder;
use App\Actions\Voice\ResolveReminder;
use App\Livewire\Agenda\AgendaPage;
use App\Models\Module;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Reminders\ReminderOccurrenceFeed;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->household = WorkspaceFactory::household();
    $this->silvas = Workspace::factory()->create(['name' => 'Silva family', 'color' => 'green']);

    // Paulo is in both — the household his default — and sees the Silvas in purple.
    $this->paulo = User::factory()->withModules([Module::REMINDERS])->inWorkspace($this->silvas, [Module::REMINDERS], 'purple')->create(['name' => 'Paulo']);
    $this->ana = User::factory()->withModules([Module::REMINDERS])->create(['name' => 'Ana']);
    $this->bia = User::factory()->inWorkspace($this->silvas, [Module::REMINDERS])->create(['name' => 'Bia']);
});

/**
 * The titles of the reminders on `$reader`'s calendar this week.
 *
 * @return list<string>
 */
function calendarTitles(User $reader): array
{
    $entries = app(CalendarFeed::class)->between($reader, CarbonImmutable::parse('2026-10-04', 'UTC'), CarbonImmutable::parse('2026-10-11', 'UTC'));

    return array_map(fn (CalendarEntry $entry): string => $entry->title, $entries);
}

test('each reader sees only the reminders of their workspaces', function () {
    app(CreateReminder::class)($this->ana, ['title' => 'Household bills', 'due_at' => '2026-10-06T10:00']);
    app(CreateReminder::class)($this->bia, ['title' => 'Silva dentist', 'due_at' => '2026-10-07T10:00']);

    $titles = fn (User $reader): array => app(ReminderOccurrenceFeed::class)->list($reader, ReminderOccurrenceFeed::PENDING)->pluck('reminder.title')->all();

    expect($titles($this->ana))->toBe(['Household bills'])
        ->and($titles($this->bia))->toBe(['Silva dentist'])
        ->and($titles($this->paulo))->toBe(['Household bills', 'Silva dentist'])
        ->and(calendarTitles($this->ana))->toBe(['Household bills'])
        ->and(calendarTitles($this->bia))->toBe(['Silva dentist'])
        ->and(calendarTitles($this->paulo))->toBe(['Household bills', 'Silva dentist'])
        ->and(app(ResolveReminder::class)($this->ana, 'Silva dentist')->isResolved())->toBeFalse()
        ->and(app(ResolveReminder::class)($this->bia, 'Silva dentist')->isResolved())->toBeTrue();

    Livewire::actingAs($this->ana)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->assertSee('Household bills')
        ->assertDontSee('Silva dentist');

    $this->actingAs($this->bia, 'sanctum')->getJson('/api/v1/reminders')
        ->assertOk()
        ->assertJsonPath('data.*.reminder.title', ['Silva dentist'])
        ->assertJsonPath('meta.counts.pending', 1);

    $this->actingAs($this->bia, 'sanctum')->getJson('/api/v1/reminders/agenda')
        ->assertOk()
        ->assertJsonPath('data.0.entries.*.reminder.title', ['Silva dentist']);
});

test('a reminder of another workspace is not found', function () {
    $reminder = app(CreateReminder::class)($this->bia, ['title' => 'Silva dentist', 'due_at' => '2026-10-07T10:00']);
    $occurrence = $reminder->occurrences()->sole();

    $this->actingAs($this->ana, 'sanctum');
    $this->getJson("/api/v1/reminders/{$reminder->friendly_id}")->assertNotFound();
    $this->patchJson("/api/v1/reminders/{$reminder->friendly_id}", ['title' => 'Mine now'])->assertNotFound();
    $this->deleteJson("/api/v1/reminders/{$reminder->friendly_id}")->assertNotFound();
    $this->postJson("/api/v1/reminder-occurrences/{$occurrence->friendly_id}/complete")->assertNotFound();

    Livewire::actingAs($this->ana)->test(AgendaPage::class)->call('edit', $reminder->friendly_id)->assertNotFound();
    Livewire::actingAs($this->ana)->test(AgendaPage::class)->call('toggle', $occurrence->friendly_id)->assertNotFound();

    expect(fn () => app(CompleteReminderOccurrence::class)($this->ana, $occurrence))
        ->toThrow(ModelNotFoundException::class)
        ->and($reminder->fresh())->not->toBeNull()
        ->and($reminder->title)->toBe('Silva dentist')
        ->and($occurrence->fresh()->completed_at)->toBeNull();
});

test('a new reminder goes to the default workspace or the one picked, never to another', function () {
    $create = app(CreateReminder::class);
    $strangers = Workspace::factory()->create();

    expect($create($this->paulo, ['title' => 'Default'])->workspace_id)->toBe($this->household->id)
        ->and($create($this->paulo, ['title' => 'Picked', 'workspace' => $this->silvas->friendly_id])->workspace_id)->toBe($this->silvas->id)
        ->and(fn () => $create($this->ana, ['title' => 'Nope', 'workspace' => $this->silvas->friendly_id]))->toThrow(ValidationException::class)
        ->and(fn () => $create($this->paulo, ['title' => 'Nope', 'workspace' => $strangers->friendly_id]))->toThrow(ValidationException::class);

    $this->actingAs($this->paulo, 'sanctum');

    $this->postJson('/api/v1/reminders', ['title' => 'From the app', 'workspace' => $this->silvas->friendly_id])
        ->assertCreated()
        ->assertJsonPath('data.workspace.id', $this->silvas->friendly_id)
        ->assertJsonPath('data.workspace.color', 'purple');

    $this->postJson('/api/v1/reminders', ['title' => 'Nope', 'workspace' => $strangers->friendly_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workspace');

    expect(Reminder::query()->where('title', 'Nope')->exists())->toBeFalse();
});

test('who is responsible must be a member of the reminder\'s workspace', function () {
    $create = app(CreateReminder::class);

    expect(Reminder::assignees($this->silvas)->pluck('name')->all())->toBe(['Bia', 'Paulo'])
        ->and(fn () => $create($this->paulo, ['title' => 'Nope', 'workspace' => $this->silvas->friendly_id, 'assigned_to' => $this->ana->friendly_id]))
        ->toThrow(ValidationException::class)
        ->and($create($this->paulo, ['title' => 'Bia\'s', 'workspace' => $this->silvas->friendly_id, 'assigned_to' => $this->bia->friendly_id])->assigned_to_id)
        ->toBe($this->bia->id);

    // Moving it where the one responsible is not: refused, unless someone of there is picked.
    $reminder = $create($this->paulo, ['title' => 'Ana\'s', 'assigned_to' => $this->ana->friendly_id]);
    $update = app(UpdateReminder::class);

    try {
        $update($this->paulo, $reminder, ['title' => 'Ana\'s', 'workspace' => $this->silvas->friendly_id]);
        $this->fail('The move was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('assigned_to');
    }

    $update($this->paulo, $reminder, ['title' => 'Bia\'s now', 'workspace' => $this->silvas->friendly_id, 'assigned_to' => $this->bia->friendly_id]);

    expect($reminder->refresh()->workspace_id)->toBe($this->silvas->id)
        ->and($reminder->assigned_to_id)->toBe($this->bia->id);

    $this->actingAs($this->paulo, 'sanctum')->getJson("/api/v1/reminders/{$reminder->friendly_id}")
        ->assertOk()
        ->assertJsonPath('meta.assignees.*.name', ['Bia', 'Paulo'])
        ->assertJsonPath("meta.assignees_by_workspace.{$this->household->friendly_id}.*.name", ['Ana', 'Paulo']);
});

test('the form offers the workspace only to a reader of two, and its assignees follow it', function () {
    Livewire::actingAs($this->ana)
        ->test(AgendaPage::class)
        ->call('create')
        ->assertDontSeeHtml('data-reminder-workspace');

    $component = Livewire::actingAs($this->paulo)
        ->test(AgendaPage::class)
        ->call('create')
        ->assertSet('workspace', $this->household->friendly_id)
        ->assertSeeHtml('data-reminder-workspace')
        ->assertSeeHtml('value="'.$this->ana->friendly_id.'"')
        ->set('assigned_to', $this->ana->friendly_id)
        ->set('workspace', $this->silvas->friendly_id)
        ->assertSet('assigned_to', $this->paulo->friendly_id)
        ->assertSeeHtml('value="'.$this->bia->friendly_id.'"')
        ->assertDontSeeHtml('value="'.$this->ana->friendly_id.'"')
        ->set('assigned_to', $this->bia->friendly_id)
        ->set('title', 'Silva groceries')
        ->call('save')
        ->assertHasNoErrors();

    $reminder = Reminder::query()->where('title', 'Silva groceries')->sole();

    expect($reminder->workspace_id)->toBe($this->silvas->id)
        ->and($reminder->assigned_to_id)->toBe($this->bia->id);

    $component->call('edit', $reminder->friendly_id)
        ->assertSet('workspace', $this->silvas->friendly_id);
});

test('agenda entries carry their workspace, in the color the reader sees it in', function () {
    app(CreateReminder::class)($this->bia, ['title' => 'Silva dentist', 'due_at' => '2026-10-07T10:00']);
    app(CreateReminder::class)($this->ana, ['title' => 'Household bills', 'due_at' => '2026-10-06T10:00']);
    $period = ['from' => '2026-10-04', 'to' => '2026-10-11'];

    $this->actingAs($this->paulo, 'sanctum')->getJson('/api/v1/agenda?'.http_build_query($period))
        ->assertOk()
        ->assertJsonPath('data.1.title', 'Silva dentist')
        ->assertJsonPath('data.1.workspace.id', $this->silvas->friendly_id)
        ->assertJsonPath('data.1.workspace.color', 'purple')
        ->assertJsonPath('data.1.workspace.color_hex', Workspace::COLORS['purple']);

    $this->actingAs($this->bia, 'sanctum')->getJson('/api/v1/agenda?'.http_build_query($period))
        ->assertOk()
        ->assertJsonPath('data.0.workspace.color', 'green');

    // On the web, a reader of two workspaces sees each item marked, and may hide one.
    Livewire::actingAs($this->paulo)
        ->test(AgendaPage::class, ['view' => 'month'])
        ->assertSeeHtml('data-agenda-workspace="'.$this->silvas->friendly_id.'"')
        ->assertSeeHtml('border-left-color: '.Workspace::COLORS['purple'])
        ->assertSee('Household bills')
        ->call('toggleWorkspace', $this->silvas->friendly_id)
        ->assertDontSee('Silva dentist')
        ->assertSee('Household bills');

    // A reader of one sees the agenda as before.
    Livewire::actingAs($this->ana)
        ->test(AgendaPage::class, ['view' => 'month'])
        ->assertSee('Household bills')
        ->assertDontSeeHtml('data-agenda-workspace')
        ->assertDontSeeHtml('data-agenda-workspace-toggle');
});
