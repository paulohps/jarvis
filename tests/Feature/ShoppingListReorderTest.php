<?php

use App\Actions\Shopping\ReorderShoppingListItems;
use App\Events\ShoppingListChanged;
use App\Livewire\Shopping\ShoppingListShow;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->withModules([Module::SHOPPING])->create();
    $this->list = ShoppingList::factory()->create(['created_by_id' => $this->user->id]);

    $this->items = collect(['Milk', 'Bread', 'Eggs', 'Rice'])->mapWithKeys(fn (string $name, int $position): array => [
        $name => ShoppingListItem::factory()->create([
            'shopping_list_id' => $this->list->id,
            'created_by_id' => $this->user->id,
            'name' => $name,
            'position' => $position,
        ]),
    ]);
});

/**
 * The names of the list's items in display order.
 *
 * @return list<string>
 */
function itemNames(ShoppingList $list): array
{
    return $list->fresh()->items()->pluck('name')->all();
}

test('the items take the given order and the change is broadcast', function () {
    Event::fake([ShoppingListChanged::class]);

    app(ReorderShoppingListItems::class)($this->user, $this->list, [
        $this->items['Rice']->friendly_id,
        $this->items['Milk']->friendly_id,
        $this->items['Eggs']->friendly_id,
        $this->items['Bread']->friendly_id,
    ]);

    expect(itemNames($this->list))->toBe(['Rice', 'Milk', 'Eggs', 'Bread']);

    Event::assertDispatched(ShoppingListChanged::class, fn (ShoppingListChanged $event): bool => $event->listId === $this->list->friendly_id
        && $event->change === ShoppingListChanged::ITEMS_REORDERED);
});

test('the same order again changes nothing and broadcasts nothing', function () {
    Event::fake([ShoppingListChanged::class]);

    app(ReorderShoppingListItems::class)($this->user, $this->list, $this->items->pluck('friendly_id')->all());

    expect(itemNames($this->list))->toBe(['Milk', 'Bread', 'Eggs', 'Rice']);
    Event::assertNotDispatched(ShoppingListChanged::class);
});

test('items left out keep their order after the given ones and unknown ids are ignored', function () {
    $otherList = ShoppingListItem::factory()->create(['name' => 'Soap', 'position' => 0]);

    app(ReorderShoppingListItems::class)($this->user, $this->list, [
        $this->items['Eggs']->friendly_id,
        'shi_removed_meanwhile',
        $otherList->friendly_id,
        $this->items['Milk']->friendly_id,
    ]);

    expect(itemNames($this->list))->toBe(['Eggs', 'Milk', 'Bread', 'Rice'])
        ->and($otherList->fresh()->position)->toBe(0);
});

test('checked items stay after the unchecked ones whatever the order', function () {
    $this->items['Milk']->forceFill(['checked_at' => now(), 'checked_by_id' => $this->user->id])->save();

    app(ReorderShoppingListItems::class)($this->user, $this->list, [
        $this->items['Milk']->friendly_id,
        $this->items['Rice']->friendly_id,
        $this->items['Bread']->friendly_id,
        $this->items['Eggs']->friendly_id,
    ]);

    expect(itemNames($this->list))->toBe(['Rice', 'Bread', 'Eggs', 'Milk']);
});

test('the order must be a list of distinct ids', function (mixed $itemIds) {
    app(ReorderShoppingListItems::class)($this->user, $this->list, $itemIds);
})->with([
    'missing' => [null],
    'empty' => [[]],
    'not a list' => [['a' => 'shi_1']],
    'repeated' => [['shi_1', 'shi_1']],
])->throws(ValidationException::class);

test('an archived list cannot be reordered', function () {
    $this->list->forceFill(['archived_at' => now()])->save();

    app(ReorderShoppingListItems::class)($this->user, $this->list, [$this->items['Rice']->friendly_id]);
})->throws(ValidationException::class);

test('a user without the shopping module cannot reorder', function () {
    $outsider = User::factory()->create();

    app(ReorderShoppingListItems::class)($outsider, $this->list, [$this->items['Rice']->friendly_id]);
})->throws(AuthorizationException::class);

test('the api reorders the items and returns the list in its new order', function () {
    $token = $this->user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/shopping/lists/{$this->list->friendly_id}/reorder", [
            'items' => [$this->items['Bread']->friendly_id, $this->items['Milk']->friendly_id],
        ])
        ->assertOk()
        ->assertJsonPath('data.items.*.name', ['Bread', 'Milk', 'Eggs', 'Rice']);

    $this->withToken($token)
        ->postJson("/api/v1/shopping/lists/{$this->list->friendly_id}/reorder", ['items' => 'Bread'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);
});

test('the web screen drags an item among the ones to pick and groups the checked ones apart', function () {
    $this->items['Eggs']->forceFill(['checked_at' => now(), 'checked_by_id' => $this->user->id])->save();

    $screen = Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $this->list])
        ->call('reorder', $this->items['Rice']->friendly_id, 0);

    expect(itemNames($this->list))->toBe(['Rice', 'Milk', 'Bread', 'Eggs']);

    $screen->assertSeeHtmlInOrder([
        'wire:sort="reorder"',
        'wire:sort:item="'.$this->items['Rice']->friendly_id.'"',
        'data-shopping-drag="'.$this->items['Rice']->friendly_id.'"',
        'wire:sort:item="'.$this->items['Bread']->friendly_id.'"',
        'Checked (1)',
        'data-shopping-item="'.$this->items['Eggs']->friendly_id.'" data-checked="true"',
    ])->assertDontSeeHtml('data-shopping-drag="'.$this->items['Eggs']->friendly_id.'"');
});

test('an archived list shows no drag handles', function () {
    $this->list->forceFill(['archived_at' => now()])->save();

    Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $this->list])
        ->assertDontSeeHtml('wire:sort')
        ->assertDontSeeHtml('data-shopping-drag');
});
