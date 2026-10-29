<?php

use App\Actions\Shopping\CompleteShoppingList;
use App\Actions\Shopping\CreateShoppingListSchedule;
use App\Actions\Shopping\SetDefaultShoppingList;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Actions\Shopping\UpdateShoppingListSchedule;
use App\Actions\Voice\ResolveShoppingList;
use App\Actions\Voice\ResolveShoppingListItem;
use App\Events\ShoppingListChanged;
use App\Livewire\Shopping\ShoppingListIndex;
use App\Livewire\Shopping\ShoppingListShow;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Models\Weekday;
use App\Models\Workspace;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->household = WorkspaceFactory::household();
    $this->beach = Workspace::factory()->create(['name' => 'Beach house', 'color' => 'orange']);

    // Only in the household; only in the beach house; in both, the household their default.
    $this->alice = User::factory()->withModules([Module::SHOPPING])->create();
    $this->bob = User::factory()->inWorkspace($this->beach, [Module::SHOPPING])->create();
    $this->carol = User::factory()->withModules([Module::SHOPPING])->inWorkspace($this->beach, [Module::SHOPPING])->create();

    $this->beachList = ShoppingList::factory()->inWorkspace($this->beach)->create(['name' => 'Beach groceries']);
    $this->beachItem = ShoppingListItem::factory()->for($this->beachList, 'list')->create(['name' => 'Sunscreen']);
});

/**
 * Every Friday at 22:00.
 *
 * @param  array<string, mixed>  $input
 */
function workspaceSchedule(User $actor, array $input = []): ShoppingListSchedule
{
    return app(CreateShoppingListSchedule::class)($actor, [
        'name' => 'Market',
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => [Weekday::FRIDAY], 'time_of_day' => '22:00'],
        ...$input,
    ]);
}

test('the web shows a list only to the members of its workspace', function () {
    $this->actingAs($this->alice)->get(route('shopping.index'))->assertOk()->assertDontSee('Beach groceries');
    $this->actingAs($this->alice)->get(route('shopping.show', $this->beachList))->assertNotFound();

    $this->actingAs($this->bob)->get(route('shopping.index'))->assertOk()->assertSee('Beach groceries');
    $this->actingAs($this->bob)->get(route('shopping.show', $this->beachList))->assertOk();

    // Acting on it is refused as not found, item actions through the list.
    expect(fn () => app(ToggleShoppingListItem::class)($this->alice, $this->beachItem, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SetDefaultShoppingList::class)($this->alice, $this->beachList))->toThrow(ModelNotFoundException::class)
        ->and($this->beachItem->refresh()->isChecked())->toBeFalse();
});

test('the api shows and changes a list only for the members of its workspace', function () {
    $schedule = workspaceSchedule($this->bob);
    $token = $this->alice->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/shopping/lists')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson('/api/v1/shopping/schedules')->assertOk()->assertJsonCount(0, 'data');

    $this->withToken($token)->getJson("/api/v1/shopping/lists/{$this->beachList->friendly_id}")->assertNotFound();
    $this->withToken($token)->patchJson("/api/v1/shopping/lists/{$this->beachList->friendly_id}", ['name' => 'Mine'])->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/shopping/lists/{$this->beachList->friendly_id}")->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/shopping/lists/{$this->beachList->friendly_id}/items", ['name' => 'Milk'])->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/shopping/items/{$this->beachItem->friendly_id}/toggle", ['checked' => true])->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/shopping/items/{$this->beachItem->friendly_id}")->assertNotFound();
    $this->withToken($token)->getJson("/api/v1/shopping/schedules/{$schedule->friendly_id}")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/shopping/schedules/{$schedule->friendly_id}")->assertNotFound();

    expect($this->beachList->refresh()->name)->toBe('Beach groceries')
        ->and($this->beachItem->exists)->toBeTrue()
        ->and($schedule->refresh()->trashed())->toBeFalse();

    // The member sees it, with its workspace in the color they see it in.
    app('auth')->forgetGuards();

    $this->withToken($this->bob->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/shopping/lists')
        ->assertOk()
        ->assertJsonPath('data.0.workspace', [
            'id' => $this->beach->friendly_id,
            'name' => 'Beach house',
            'color' => 'orange',
            'color_hex' => Workspace::COLORS['orange'],
        ]);
});

test('the api creates a list in the default workspace or the one chosen', function () {
    $token = $this->carol->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/shopping/lists', ['name' => 'Groceries'])
        ->assertCreated()
        ->assertJsonPath('data.workspace.id', $this->household->friendly_id);

    $this->withToken($token)
        ->postJson('/api/v1/shopping/lists', ['name' => 'Snacks', 'workspace' => $this->beach->friendly_id])
        ->assertCreated()
        ->assertJsonPath('data.workspace.id', $this->beach->friendly_id);

    $this->withToken($token)
        ->postJson('/api/v1/shopping/schedules', [
            'name' => 'Market',
            'workspace' => $this->beach->friendly_id,
            'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => [Weekday::FRIDAY], 'time_of_day' => '22:00'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.workspace.id', $this->beach->friendly_id);

    // A workspace where the user has no shopping is refused.
    app('auth')->forgetGuards();

    $this->withToken($this->alice->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/shopping/lists', ['name' => 'Sneaky', 'workspace' => $this->beach->friendly_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workspace']);

    app('auth')->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/shopping/lists', ['name' => 'Nowhere', 'workspace' => 'wsp_missing'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workspace']);

    expect(ShoppingList::query()->where('name', 'Snacks')->sole()->workspace_id)->toBe($this->beach->id)
        ->and(ShoppingList::query()->where('name', 'Groceries')->sole()->workspace_id)->toBe($this->household->id)
        ->and(ShoppingList::query()->whereIn('name', ['Sneaky', 'Nowhere'])->exists())->toBeFalse();
});

test('the api moves a list to another workspace of the user', function () {
    $list = ShoppingList::factory()->asDefault()->create(['name' => 'Groceries']);

    $this->withToken($this->carol->createToken('iPhone')->plainTextToken)
        ->patchJson("/api/v1/shopping/lists/{$list->friendly_id}", ['workspace' => $this->beach->friendly_id])
        ->assertOk()
        ->assertJsonPath('data.name', 'Groceries')
        ->assertJsonPath('data.workspace.id', $this->beach->friendly_id)
        // The beach house may have a default of its own.
        ->assertJsonPath('data.is_default', false);

    app('auth')->forgetGuards();

    $this->withToken($this->alice->createToken('iPhone')->plainTextToken)
        ->getJson("/api/v1/shopping/lists/{$list->friendly_id}")
        ->assertNotFound();
});

test('the web asks for the workspace only of a user with shopping in several', function () {
    Livewire::actingAs($this->alice)
        ->test(ShoppingListIndex::class)
        ->assertDontSee('data-shopping-workspace', false)
        ->call('create')
        ->assertDontSee('data-shopping-list-workspace', false);

    Livewire::actingAs($this->carol)
        ->test(ShoppingListIndex::class)
        ->assertSee('data-shopping-workspace="'.$this->beach->friendly_id.'"', false)
        ->call('create')
        ->assertSet('workspace', $this->household->friendly_id)
        ->assertSee('data-shopping-list-workspace', false)
        ->set('name', 'Snacks')
        ->set('workspace', $this->beach->friendly_id)
        ->call('save')
        ->assertHasNoErrors();

    expect(ShoppingList::query()->where('name', 'Snacks')->sole()->workspace_id)->toBe($this->beach->id);

    // A schedule goes to the workspace picked in its sheet.
    Livewire::actingAs($this->carol)
        ->test(ShoppingListIndex::class, ['filter' => 'recurring'])
        ->call('createSchedule')
        ->set('scheduleName', 'Market')
        ->set('recurrence.weekdays', [Weekday::FRIDAY])
        ->set('scheduleWorkspace', $this->beach->friendly_id)
        ->call('saveSchedule')
        ->assertHasNoErrors();

    expect(ShoppingListSchedule::query()->where('name', 'Market')->sole()->workspace_id)->toBe($this->beach->id);
});

test('each workspace has its own default list', function () {
    $groceries = ShoppingList::factory()->create(['name' => 'Groceries']);

    app(SetDefaultShoppingList::class)($this->carol, $groceries);
    app(SetDefaultShoppingList::class)($this->carol, $this->beachList);

    // Making the beach house's default leaves the household's.
    expect($groceries->refresh()->is_default)->toBeTrue()
        ->and($this->beachList->refresh()->is_default)->toBeTrue()
        ->and(ShoppingList::defaultIn($this->household)?->is($groceries))->toBeTrue()
        ->and(ShoppingList::defaultIn($this->beach)?->is($this->beachList))->toBeTrue()
        // A list named by nobody: the default of the user's default workspace.
        ->and(ShoppingList::defaultFor($this->carol)?->is($groceries))->toBeTrue()
        ->and(ShoppingList::defaultFor($this->bob)?->is($this->beachList))->toBeTrue();

    $other = ShoppingList::factory()->inWorkspace($this->beach)->create();
    app(SetDefaultShoppingList::class)($this->bob, $other);

    expect($this->beachList->refresh()->is_default)->toBeFalse()
        ->and($groceries->refresh()->is_default)->toBeTrue();
});

test('the lists a schedule makes belong to its workspace and inherit its default', function () {
    ShoppingList::factory()->asDefault()->create(['name' => 'Groceries']);

    $schedule = workspaceSchedule($this->carol, ['workspace' => $this->beach->friendly_id]);
    $first = $schedule->openList();

    expect($schedule->workspace_id)->toBe($this->beach->id)
        ->and($first->workspace_id)->toBe($this->beach->id);

    app(SetDefaultShoppingList::class)($this->carol, $first);
    app(CompleteShoppingList::class)($this->carol, $first->refresh());

    // The household's default does not keep the beach house's next list from being its default.
    $next = $schedule->openList();

    expect($next->isNot($first))->toBeTrue()
        ->and($next->workspace_id)->toBe($this->beach->id)
        ->and($next->is_default)->toBeTrue();

    // Moving the schedule takes its open list along.
    app(UpdateShoppingListSchedule::class)($this->carol, $schedule, [
        'name' => 'Market',
        'workspace' => $this->household->friendly_id,
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => [Weekday::FRIDAY], 'time_of_day' => '22:00'],
    ]);

    expect($schedule->refresh()->workspace_id)->toBe($this->household->id)
        ->and($next->refresh()->workspace_id)->toBe($this->household->id)
        ->and($next->is_default)->toBeFalse()
        ->and($first->refresh()->workspace_id)->toBe($this->beach->id);

    // Someone not in the new workspace can no longer change it.
    expect(fn () => app(UpdateShoppingListSchedule::class)($this->bob, $schedule, ['name' => 'Mine']))->toThrow(ModelNotFoundException::class);
});

test('the agenda and voice commands only reach the lists of the user\'s workspaces', function () {
    $schedule = workspaceSchedule($this->bob);
    ShoppingList::factory()->create(['name' => 'Groceries']);

    $entries = fn (User $user): array => app(CalendarFeed::class)->between($user, CarbonImmutable::parse('2026-10-05', 'UTC'), CarbonImmutable::parse('2026-10-20', 'UTC'));

    expect($entries($this->alice))->toBe([])
        ->and(array_map(fn (CalendarEntry $entry): ?int => $entry->workspace?->id, $entries($this->bob)))->toBe([$this->beach->id, $this->beach->id])
        ->and($schedule->exists)->toBeTrue();

    expect(app(ResolveShoppingList::class)($this->alice, 'beach groceries')->isResolved())->toBeFalse()
        ->and(app(ResolveShoppingList::class)($this->alice, null)->match?->name)->toBe('Groceries')
        ->and(app(ResolveShoppingList::class)($this->alice, $this->beachList->friendly_id)->isResolved())->toBeFalse()
        ->and(app(ResolveShoppingListItem::class)($this->alice, 'sunscreen', null)->isResolved())->toBeFalse()
        ->and(app(ResolveShoppingListItem::class)($this->bob, 'sunscreen', null)->match?->is($this->beachItem))->toBeTrue();
});

test('the list channel is only for the members of its workspace', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');

    $auth = fn (User $user) => $this->actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-'.ShoppingListChanged::channelFor($this->beachList->friendly_id),
    ]);

    $auth($this->bob)->assertOk();
    $auth($this->carol)->assertOk();
    $auth($this->alice)->assertForbidden();
});

test('a list moved away from the user leaves their screen', function () {
    Livewire::actingAs($this->bob)
        ->test(ShoppingListShow::class, ['list' => $this->beachList])
        ->assertDontSee('data-shopping-workspace', false)
        ->tap(fn () => $this->beachList->update(['workspace_id' => $this->household->id]))
        ->call('refreshList')
        ->assertRedirect(route('shopping.index'));
});
