<?php

use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\DeleteShoppingList;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Events\ShoppingListChanged;
use App\Livewire\Shopping\ShoppingListIndex;
use App\Livewire\Shopping\ShoppingListShow;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::SHOPPING])->create();
    $this->member = User::factory()->withModules([Module::SHOPPING])->create();
});

test('shopping pages need the module', function () {
    $list = ShoppingList::factory()->create(['name' => 'Groceries']);
    $outsider = User::factory()->withModules([Module::REMINDERS])->create();

    $this->actingAs($this->user)->get(route('shopping.index'))->assertOk()->assertSee('Groceries');
    $this->actingAs($this->user)->get(route('shopping.show', $list))->assertOk()->assertSee('data-shopping-add-item', false);

    $this->actingAs($outsider)->get(route('shopping.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('shopping.show', $list))->assertForbidden();

    // A deleted list is gone for everyone.
    $list->delete();
    $this->actingAs($this->user)->get(route('shopping.show', $list))->assertNotFound();
});

test('index shows active lists and archived ones apart', function () {
    $groceries = ShoppingList::factory()->create(['name' => 'Weekly market']);
    ShoppingListItem::factory()->for($groceries, 'list')->create();
    ShoppingListItem::factory()->for($groceries, 'list')->checked()->create();
    ShoppingList::factory()->archived()->create(['name' => 'Christmas']);
    ShoppingList::factory()->create(['name' => 'Deleted one'])->delete();

    Livewire::actingAs($this->member)
        ->test(ShoppingListIndex::class)
        ->assertSee('Weekly market')
        ->assertSee('1 of 2 to pick')
        ->assertDontSee('Christmas')
        ->assertDontSee('Deleted one')
        ->set('filter', 'archived')
        ->assertSee('Christmas')
        ->assertDontSee('Weekly market')
        ->set('filter', 'bogus')
        ->assertSet('filter', 'active');

    // Creating a list opens it.
    $component = Livewire::actingAs($this->user)
        ->test(ShoppingListIndex::class)
        ->call('create')
        ->set('name', str_repeat('a', 101))
        ->call('save')
        ->assertHasErrors('name')
        ->set('name', 'Pharmacy')
        ->call('save')
        ->assertHasNoErrors();

    $created = ShoppingList::query()->where('name', 'Pharmacy')->sole();
    $component->assertRedirect(route('shopping.show', $created));
});

test('list screen makes the list the default and index marks it', function () {
    $list = ShoppingList::factory()->create(['name' => 'Groceries']);

    Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $list])
        ->assertSee('data-shopping-default="off"', false)
        ->call('toggleDefault')
        ->assertSee('data-shopping-default="on"', false);

    expect($list->refresh()->is_default)->toBeTrue();

    Livewire::actingAs($this->member)
        ->test(ShoppingListIndex::class)
        ->assertSee('data-shopping-list-default', false);
});

test('list screen adds items and puts checked ones at the end', function () {
    $list = ShoppingList::factory()->create(['name' => 'Groceries']);

    $screen = Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $list])
        ->set('itemName', '')
        ->call('addItem')
        ->assertHasErrors('name')
        ->set('itemName', 'Milk')
        ->set('itemNote', '2 L')
        ->call('addItem')
        ->assertHasNoErrors()
        ->assertSet('itemName', '')
        ->assertSet('itemNote', '')
        ->set('itemName', 'Bread')
        ->call('addItem')
        ->set('itemName', 'Eggs')
        ->call('addItem');

    [$milk, $bread, $eggs] = $list->items()->get()->all();

    $screen->call('toggle', $milk->friendly_id);

    expect($screen->get('items')->pluck('name')->all())->toBe(['Bread', 'Eggs', 'Milk']);

    $screen->assertSeeHtmlInOrder([
        'data-shopping-item="'.$bread->friendly_id.'"',
        'data-shopping-item="'.$eggs->friendly_id.'"',
        'data-shopping-item="'.$milk->friendly_id.'" data-checked="true"',
        'line-through',
        'Clear checked',
    ]);

    // Editing and removing an item.
    $screen->call('editItem', $bread->friendly_id)
        ->assertSet('editName', 'Bread')
        ->set('editName', 'Rye bread')
        ->set('editNote', '1 loaf')
        ->call('updateItem')
        ->assertSet('showItemForm', false);

    expect($bread->refresh()->only(['name', 'quantity_note']))->toBe(['name' => 'Rye bread', 'quantity_note' => '1 loaf']);

    $screen->call('editItem', $eggs->friendly_id)->call('removeItem');

    expect(ShoppingListItem::query()->whereKey($eggs->id)->exists())->toBeFalse();

    // "Clear checked" removes only the checked ones.
    $screen->call('clearChecked');

    expect($list->items()->pluck('id')->all())->toBe([$bread->id]);
    $screen->assertDontSee('Clear checked');
});

test('list screen follows changes made in another window', function () {
    $list = ShoppingList::factory()->create(['name' => 'Groceries']);
    $milk = app(AddShoppingListItem::class)($this->member, $list, ['name' => 'Milk']);

    $screen = Livewire::actingAs($this->user)->test(ShoppingListShow::class, ['list' => $list]);

    // It listens on the list's private channel.
    expect($screen->instance()->getListeners())
        ->toHaveKey('echo-private:shopping-lists.'.$list->friendly_id.',.shopping-list.changed', 'refreshList');

    $screen->assertSeeHtml('data-shopping-item="'.$milk->friendly_id.'" data-checked="false"');

    // The other window checks the item: the broadcast reloads this one.
    app(ToggleShoppingListItem::class)($this->member, $milk, true);

    $screen->dispatch('echo-private:'.ShoppingListChanged::channelFor($list->friendly_id).',.shopping-list.changed', ['change' => ShoppingListChanged::ITEM_TOGGLED])
        ->assertSeeHtml('data-shopping-item="'.$milk->friendly_id.'" data-checked="true"');

    // The list is deleted in the other window: this one goes back to the lists.
    app(DeleteShoppingList::class)($this->member, $list);

    $screen->call('refreshList')->assertRedirect(route('shopping.index'));
});

test('archived list is read only on screen', function () {
    $list = ShoppingList::factory()->archived()->create(['name' => 'Christmas']);
    $item = ShoppingListItem::factory()->for($list, 'list')->create(['name' => 'Turkey']);

    $screen = Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $list])
        ->assertSee('Turkey')
        ->assertSee('data-shopping-archived-notice', false)
        ->assertDontSee('data-shopping-add-item', false)
        ->call('toggle', $item->friendly_id)
        ->assertHasErrors('list');

    expect($item->refresh()->checked_at)->toBeNull();

    $screen->call('unarchive')->assertSee('data-shopping-add-item', false);

    // Renaming and archiving from the screen.
    $screen->call('editList')
        ->assertSet('listName', 'Christmas')
        ->set('listName', 'Christmas dinner')
        ->call('renameList')
        ->assertHasNoErrors();

    expect($list->refresh()->name)->toBe('Christmas dinner');

    $screen->call('archive')->assertRedirect(route('shopping.index'));

    expect($list->refresh()->isArchived())->toBeTrue();
});
