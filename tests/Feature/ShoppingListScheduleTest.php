<?php

use App\Actions\Shopping\CompleteShoppingList;
use App\Actions\Shopping\CreateShoppingListSchedule;
use App\Actions\Shopping\DeleteShoppingListSchedule;
use App\Actions\Shopping\UnarchiveShoppingList;
use App\Actions\Shopping\UpdateShoppingListSchedule;
use App\Livewire\Shopping\ShoppingListIndex;
use App\Livewire\Shopping\ShoppingListShow;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\ShoppingList;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Models\Weekday;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    // 09:00 on Monday, October 5, in São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::SHOPPING])->create(['timezone' => 'America/Sao_Paulo']);
});

/**
 * Every Friday at 22:00 in São Paulo: deadlines at 01:00 UTC on Saturdays.
 *
 * @param  array<string, mixed>  $input
 */
function marketSchedule(User $actor, array $input = []): ShoppingListSchedule
{
    return app(CreateShoppingListSchedule::class)($actor, [
        'name' => 'Market',
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => [Weekday::FRIDAY], 'time_of_day' => '22:00'],
        ...$input,
    ]);
}

/**
 * @return list<string>
 */
function scheduledListItemNames(ShoppingList $list): array
{
    return $list->items()->pluck('name')->all();
}

test('a recurring list makes its first list for the first deadline, from the template', function () {
    $schedule = marketSchedule($this->user, [
        'next_list_content' => 'template_and_pending',
        'template_items' => ['Milk', ' Bread ', '', 'milk'],
    ]);

    $list = $schedule->openList();

    expect($list->name)->toBe('Market · Fri, 9 Oct')
        ->and($list->due_at->toIso8601String())->toBe('2026-10-10T01:00:00+00:00')
        ->and($schedule->template_items)->toBe(['Milk', 'Bread', 'milk'])
        // The template repeats no name.
        ->and(scheduledListItemNames($list))->toBe(['Milk', 'Bread']);
});

test('completing a list ahead of its deadline makes the next one, with the items left unchecked', function () {
    $schedule = marketSchedule($this->user);
    $first = $schedule->openList();
    $first->update(['is_default' => true]);
    $rice = $first->items()->create(['name' => 'Rice', 'position' => 0, 'created_by_id' => $this->user->id]);
    $first->items()->create(['name' => 'Beans', 'position' => 1, 'created_by_id' => $this->user->id]);
    $rice->update(['checked_at' => now()]);

    app(CompleteShoppingList::class)($this->user, $first);

    $next = $schedule->openList();

    expect($first->refresh()->completed_at)->not->toBeNull()
        ->and($first->completed_by_id)->toBe($this->user->id)
        ->and($first->isArchived())->toBeTrue()
        ->and($first->is_default)->toBeFalse()
        ->and($next->due_at->toIso8601String())->toBe('2026-10-17T01:00:00+00:00')
        ->and($next->is_default)->toBeTrue()
        ->and(scheduledListItemNames($next))->toBe(['Beans']);

    // The deadline of the completed list makes nothing more.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 01:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($schedule->lists()->count())->toBe(2);
});

test('a list that closes by itself is completed at its deadline and the next one is made', function () {
    $schedule = marketSchedule($this->user, ['next_list_content' => 'all']);
    $first = $schedule->openList();
    $milk = $first->items()->create(['name' => 'Milk', 'position' => 0, 'created_by_id' => $this->user->id]);
    $milk->update(['checked_at' => now()]);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 01:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $next = $schedule->openList();

    expect($first->refresh()->completed_at)->not->toBeNull()
        ->and($first->completed_by_id)->toBeNull()
        ->and($next->due_at->toIso8601String())->toBe('2026-10-17T01:00:00+00:00')
        // All of them, unchecked again.
        ->and(scheduledListItemNames($next))->toBe(['Milk'])
        ->and($next->items()->sole()->checked_at)->toBeNull();
});

test('a list closed by hand stays open past its deadline, holding the next one back when the schedule waits', function (bool $waitsForClose, int $listsAfterDeadline) {
    $schedule = marketSchedule($this->user, ['closes_automatically' => false, 'waits_for_close' => $waitsForClose]);
    $first = $schedule->openList();

    $this->travelTo(CarbonImmutable::parse('2026-10-10 01:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($first->refresh()->completed_at)->toBeNull()
        ->and($first->isOverdue())->toBeTrue()
        ->and($schedule->lists()->count())->toBe($listsAfterDeadline);

    // Closed on Sunday: the next deadline without a list.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 15:00', 'UTC'));
    app(CompleteShoppingList::class)($this->user, $first);

    expect($schedule->lists()->count())->toBe(2)
        ->and($schedule->openList()->due_at->toIso8601String())->toBe('2026-10-17T01:00:00+00:00');
})->with([
    'waits for it' => [true, 1],
    'does not wait' => [false, 2],
]);

test('an empty next list starts without items', function () {
    $schedule = marketSchedule($this->user, ['next_list_content' => 'empty']);
    $first = $schedule->openList();
    $first->items()->create(['name' => 'Rice', 'position' => 0, 'created_by_id' => $this->user->id]);

    app(CompleteShoppingList::class)($this->user, $first);

    expect(scheduledListItemNames($schedule->openList()))->toBe([]);
});

test('changing the rule moves the open list to the new deadline', function () {
    $schedule = marketSchedule($this->user);
    $list = $schedule->openList();

    app(UpdateShoppingListSchedule::class)($this->user, $schedule, [
        'name' => 'Market',
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => [Weekday::SATURDAY], 'time_of_day' => '12:00'],
        'closes_automatically' => false,
    ]);

    expect($schedule->refresh()->closes_automatically)->toBeFalse()
        ->and($schedule->lists()->count())->toBe(1)
        ->and($list->refresh()->due_at->toIso8601String())->toBe('2026-10-10T15:00:00+00:00')
        ->and($list->name)->toBe('Market · Sat, 10 Oct');
});

test('a deleted recurring list makes no more lists, and the ones it made stay', function () {
    $schedule = marketSchedule($this->user);
    $list = $schedule->openList();

    app(DeleteShoppingListSchedule::class)($this->user, $schedule);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 01:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($schedule->refresh()->trashed())->toBeTrue()
        ->and($schedule->recurrence->is_paused)->toBeTrue()
        ->and(ShoppingList::query()->count())->toBe(1)
        ->and($list->refresh()->completed_at)->toBeNull();
});

test('unarchiving a completed list opens it again', function () {
    $schedule = marketSchedule($this->user);
    $list = app(CompleteShoppingList::class)($this->user, $schedule->openList());

    app(UnarchiveShoppingList::class)($this->user, $list);

    expect($list->refresh()->completed_at)->toBeNull()
        ->and($list->completed_by_id)->toBeNull()
        ->and($list->isArchived())->toBeFalse();
});

test('a recurring list needs a name and a valid rule, and the module', function () {
    expect(fn () => marketSchedule($this->user, ['name' => ' ']))->toThrow(ValidationException::class)
        ->and(fn () => marketSchedule($this->user, ['recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => []]]))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('recurrence.weekdays'))
        ->and(fn () => marketSchedule($this->user, ['next_list_content' => 'everything']))->toThrow(ValidationException::class)
        ->and(fn () => marketSchedule(User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and(ShoppingListSchedule::query()->count())->toBe(0);
});

test('the recurring tab sets up, edits and stops a recurring list', function () {
    $component = Livewire::actingAs($this->user)
        ->test(ShoppingListIndex::class, ['filter' => 'recurring'])
        ->assertSee('No recurring lists')
        ->call('createSchedule')
        ->assertSet('recurrence.frequency', RecurrenceFrequency::WEEKLY)
        ->assertSet('recurrence.time_of_day', '22:00')
        ->call('saveSchedule')
        ->assertHasErrors('scheduleName')
        ->set('scheduleName', 'Market')
        ->call('saveSchedule')
        ->assertHasErrors('recurrence.weekdays')
        ->set('recurrence.weekdays', [Weekday::FRIDAY])
        ->set('closes_automatically', false)
        ->set('waits_for_close', true)
        ->set('next_list_content', 'template_and_pending')
        ->set('template', "Milk\n\nBread")
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSee('Recurring list “Market” saved.')
        ->assertSee('Weekly · Fri · 22:00')
        ->assertSee('Closed by hand');

    $schedule = ShoppingListSchedule::query()->sole();

    expect($schedule->waits_for_close)->toBeTrue()
        ->and(scheduledListItemNames($schedule->openList()))->toBe(['Milk', 'Bread']);

    $component->call('editSchedule', $schedule->friendly_id)
        ->assertSet('scheduleName', 'Market')
        ->assertSet('template', "Milk\nBread")
        ->assertSet('recurrence.weekdays', [Weekday::FRIDAY])
        ->call('deleteSchedule', $schedule->friendly_id)
        ->assertSee('Recurring list “Market” stopped.');

    expect($schedule->refresh()->trashed())->toBeTrue();
});

test('completing a recurring list on its screen goes to the next one', function () {
    $schedule = marketSchedule($this->user);
    $first = $schedule->openList();

    $component = Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $first])
        ->assertSeeHtml('data-shopping-deadline')
        ->assertDontSeeHtml('data-shopping-archive')
        ->call('complete');

    $component->assertRedirect(route('shopping.show', $schedule->openList()));

    Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $first])
        ->assertSee('Completed by '.$this->user->name);
});

test('the agenda shows the deadlines of the recurring lists', function () {
    $schedule = marketSchedule($this->user);

    $titles = array_map(
        fn (CalendarEntry $entry): string => $entry->title.($entry->isProjected ? ' (projected)' : ''),
        app(CalendarFeed::class)->between($this->user, CarbonImmutable::parse('2026-10-05', 'UTC'), CarbonImmutable::parse('2026-10-20', 'UTC')),
    );

    expect($titles)->toBe(['Market · Fri, 9 Oct', 'Market (projected)'])
        ->and(app(CalendarFeed::class)->keysFor($this->user))->toContain(CalendarFeed::SHOPPING);
});

test('the api completes a recurring list and shows its deadline', function () {
    $schedule = marketSchedule($this->user);
    $first = $schedule->openList();
    $token = $this->user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/shopping/lists/{$first->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.is_recurring', true)
        ->assertJsonPath('data.due_at', '2026-10-10T01:00:00+00:00')
        ->assertJsonPath('data.is_overdue', false);

    $this->withToken($token)
        ->postJson("/api/v1/shopping/lists/{$first->friendly_id}/complete")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true)
        ->assertJsonPath('data.completed_at', '2026-10-05T12:00:00+00:00');

    expect($schedule->openList()->due_at->toIso8601String())->toBe('2026-10-17T01:00:00+00:00');
});
