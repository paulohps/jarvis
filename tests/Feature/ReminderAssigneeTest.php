<?php

use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\UpdateReminder;
use App\Livewire\Agenda\AgendaPage;
use App\Models\Module;
use App\Models\NotificationType;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::REMINDERS])->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
    $this->member = User::factory()->withModules([Module::REMINDERS])->create(['name' => 'Ana']);
});

test('a new reminder is the creator\'s unless someone else or nobody is picked', function () {
    $create = app(CreateReminder::class);

    expect($create($this->user, ['title' => 'Mine'])->assigned_to_id)->toBe($this->user->id)
        ->and($create($this->user, ['title' => 'Ana\'s', 'assigned_to' => $this->member->friendly_id])->assigned_to_id)->toBe($this->member->id)
        ->and($create($this->user, ['title' => 'Nobody\'s', 'assigned_to' => null])->assigned_to_id)->toBeNull();
});

test('an edit without the field keeps who is responsible', function () {
    $reminder = app(CreateReminder::class)($this->user, ['title' => 'Groceries', 'assigned_to' => $this->member->friendly_id]);

    app(UpdateReminder::class)($this->user, $reminder, ['title' => 'Groceries today']);
    expect($reminder->refresh()->assigned_to_id)->toBe($this->member->id);

    app(UpdateReminder::class)($this->user, $reminder, ['title' => 'Groceries today', 'assigned_to' => null]);
    expect($reminder->refresh()->assigned_to_id)->toBeNull();
});

test('only active users with the module can be made responsible', function (Closure $makeUser) {
    $outsider = $makeUser();

    expect(fn () => app(CreateReminder::class)($this->user, ['title' => 'Nope', 'assigned_to' => $outsider->friendly_id]))
        ->toThrow(ValidationException::class);
})->with([
    'without the module' => [fn () => User::factory()->create()],
    'deactivated' => [fn () => User::factory()->withModules([Module::REMINDERS])->deactivated()->create()],
]);

test('someone who lost the module stays responsible through an edit', function () {
    $reminder = app(CreateReminder::class)($this->user, ['title' => 'Bills', 'assigned_to' => $this->member->friendly_id]);
    $this->member->membershipOf(WorkspaceFactory::household())->modules()->detach();

    app(UpdateReminder::class)($this->user, $reminder, ['title' => 'Bills (late)', 'assigned_to' => $this->member->friendly_id]);

    expect($reminder->refresh()->assigned_to_id)->toBe($this->member->id);
});

test('the one responsible gets reminder due, the creator when nobody is', function () {
    $this->artisan('notifications:provision')->assertSuccessful();

    app(CreateReminder::class)($this->user, ['title' => 'Ana\'s', 'due_at' => '2026-10-05T10:00', 'assigned_to' => $this->member->friendly_id]);
    app(CreateReminder::class)($this->user, ['title' => 'Nobody\'s', 'due_at' => '2026-10-05T10:00', 'assigned_to' => null]);

    // 10:00 in São Paulo is 13:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00:10', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($this->member->notifications()->sole()->data['key'])->toBe(NotificationType::REMINDER_DUE)
        ->and($this->member->notifications()->sole()->data['title'])->toContain('Ana\'s')
        ->and($this->user->notifications()->sole()->data['title'])->toContain('Nobody\'s');
});

test('only mine narrows the list and its counts to the reader\'s reminders', function () {
    app(CreateReminder::class)($this->user, ['title' => 'Paulo\'s task']);
    app(CreateReminder::class)($this->user, ['title' => 'Ana\'s task', 'assigned_to' => $this->member->friendly_id]);
    app(CreateReminder::class)($this->user, ['title' => 'Unassigned task', 'assigned_to' => null]);

    Livewire::actingAs($this->member)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->assertSee('Paulo\'s task')
        ->assertSee('Unassigned task')
        ->assertSee('Pending (3)')
        ->set('mine', true)
        ->assertSee('Ana\'s task')
        ->assertDontSee('Paulo\'s task')
        ->assertDontSee('Unassigned task')
        ->assertSee('Pending (1)');
});

test('the form picks who is responsible, the reader by default', function () {
    $component = Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'tasks'])
        ->call('create')
        ->assertSet('assigned_to', $this->user->friendly_id)
        ->assertSeeHtml('value="'.$this->member->friendly_id.'"')
        ->set('title', 'Take the trash out')
        ->set('assigned_to', $this->member->friendly_id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-reminder-assignee="'.$this->member->friendly_id.'"');

    $reminder = Reminder::query()->where('title', 'Take the trash out')->sole();
    expect($reminder->assigned_to_id)->toBe($this->member->id);

    $component->call('edit', $reminder->friendly_id)
        ->assertSet('assigned_to', $this->member->friendly_id)
        ->set('assigned_to', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($reminder->refresh()->assigned_to_id)->toBeNull();
});

test('the api shows, filters and keeps who is responsible', function () {
    $token = $this->user->createToken('iPhone')->plainTextToken;
    $mine = app(CreateReminder::class)($this->user, ['title' => 'Mine']);
    app(CreateReminder::class)($this->user, ['title' => 'Ana\'s', 'assigned_to' => $this->member->friendly_id]);

    $this->withToken($token)->getJson('/api/v1/reminders?mine=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reminder.assigned_to.friendly_id', $this->user->friendly_id)
        ->assertJsonPath('meta.mine', true)
        ->assertJsonPath('meta.counts.pending', 1)
        ->assertJsonPath('meta.assignees.*.name', ['Ana', 'Paulo']);

    $this->withToken($token)->getJson('/api/v1/reminders')->assertJsonCount(2, 'data');

    // An app that does not know the field keeps the one responsible.
    $this->withToken($token)->patchJson("/api/v1/reminders/{$mine->friendly_id}", ['title' => 'Still mine'])
        ->assertOk()
        ->assertJsonPath('data.assigned_to.friendly_id', $this->user->friendly_id);

    $this->withToken($token)->patchJson("/api/v1/reminders/{$mine->friendly_id}", ['title' => 'Ana\'s now', 'assigned_to' => $this->member->friendly_id])
        ->assertOk()
        ->assertJsonPath('data.assigned_to.name', 'Ana');
});
