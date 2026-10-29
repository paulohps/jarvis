<?php

use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\ArchiveShoppingList;
use App\Actions\Shopping\CreateShoppingList;
use App\Actions\Shopping\DeleteShoppingList;
use App\Actions\Shopping\RemoveShoppingListItem;
use App\Actions\Shopping\RenameShoppingList;
use App\Actions\Shopping\SetDefaultShoppingList;
use App\Actions\Shopping\UnarchiveShoppingList;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->creator = User::factory()->withModules([Module::SHOPPING])->create();
    $this->member = User::factory()->withModules([Module::SHOPPING])->create();
});

/**
 * The validation errors of a call, or an empty array when it passes.
 *
 * @return array<string, list<string>>
 */
function shoppingErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('list name is required and limited to 100 chars', function () {
    $list = app(CreateShoppingList::class)($this->creator, ['name' => '  Groceries  ']);

    expect($list->name)->toBe('Groceries')
        ->and($list->created_by_id)->toBe($this->creator->id)
        ->and($list->friendly_id)->toStartWith('shl');

    expect(shoppingErrors(fn () => app(CreateShoppingList::class)($this->creator, ['name' => '   '])))->toHaveKey('name')
        ->and(shoppingErrors(fn () => app(CreateShoppingList::class)($this->creator, [])))->toHaveKey('name')
        ->and(shoppingErrors(fn () => app(CreateShoppingList::class)($this->creator, ['name' => str_repeat('a', 101)])))->toHaveKey('name')
        ->and(shoppingErrors(fn () => app(CreateShoppingList::class)($this->creator, ['name' => str_repeat('a', 100)])))->toBe([]);

    // Renaming follows the same rules, and any member may rename.
    expect(shoppingErrors(fn () => app(RenameShoppingList::class)($this->member, $list, ['name' => str_repeat('b', 101)])))->toHaveKey('name')
        ->and($list->refresh()->name)->toBe('Groceries');

    app(RenameShoppingList::class)($this->member, $list, ['name' => 'Market']);

    expect($list->refresh()->name)->toBe('Market')
        ->and($list->created_by_id)->toBe($this->creator->id);
});

test('archived lists are excluded from the main list', function () {
    $active = app(CreateShoppingList::class)($this->creator, ['name' => 'Groceries']);
    $archived = app(CreateShoppingList::class)($this->creator, ['name' => 'Christmas']);
    $deleted = app(CreateShoppingList::class)($this->creator, ['name' => 'Old one']);
    $item = app(AddShoppingListItem::class)($this->creator, $archived, ['name' => 'Turkey']);

    app(ArchiveShoppingList::class)($this->member, $archived);
    app(DeleteShoppingList::class)($this->member, $deleted);

    expect(ShoppingList::query()->notArchived()->pluck('id')->all())->toBe([$active->id])
        ->and(ShoppingList::query()->archived()->pluck('id')->all())->toBe([$archived->id])
        ->and($deleted->refresh()->trashed())->toBeTrue()
        // Archived lists keep their items, read only.
        ->and($archived->items()->pluck('id')->all())->toBe([$item->id])
        ->and(shoppingErrors(fn () => app(AddShoppingListItem::class)($this->creator, $archived, ['name' => 'Wine'])))->toHaveKey('list')
        ->and(shoppingErrors(fn () => app(RemoveShoppingListItem::class)($this->creator, $item)))->toHaveKey('list');

    // Archiving again keeps the first date; unarchiving brings it back.
    $archivedAt = $archived->refresh()->archived_at;
    $this->travel(1)->hours();
    app(ArchiveShoppingList::class)($this->creator, $archived);
    expect($archived->refresh()->archived_at->equalTo($archivedAt))->toBeTrue();

    app(UnarchiveShoppingList::class)($this->creator, $archived);

    expect(ShoppingList::query()->notArchived()->pluck('id')->sort()->values()->all())->toBe([$active->id, $archived->id]);
});

test('one active list at most is the default', function () {
    $market = ShoppingList::factory()->create(['name' => 'Market']);
    $pharmacy = ShoppingList::factory()->create(['name' => 'Pharmacy']);

    app(SetDefaultShoppingList::class)($this->creator, $market);
    app(SetDefaultShoppingList::class)($this->member, $pharmacy);

    // Making another one the default switches the previous one off.
    expect(ShoppingList::defaultIn(WorkspaceFactory::household())?->is($pharmacy))->toBeTrue()
        ->and($market->refresh()->is_default)->toBeFalse();

    // Unsetting leaves the household without a default.
    app(SetDefaultShoppingList::class)($this->creator, $pharmacy, false);

    expect(ShoppingList::defaultIn(WorkspaceFactory::household()))->toBeNull();

    // Archiving or deleting the default unsets it.
    app(SetDefaultShoppingList::class)($this->creator, $market);
    app(ArchiveShoppingList::class)($this->creator, $market);

    expect($market->refresh()->is_default)->toBeFalse()
        ->and(ShoppingList::defaultIn(WorkspaceFactory::household()))->toBeNull();

    app(SetDefaultShoppingList::class)($this->creator, $pharmacy);
    app(DeleteShoppingList::class)($this->creator, $pharmacy);

    expect(ShoppingList::withTrashed()->where('is_default', true)->exists())->toBeFalse();

    // An archived list cannot become the default.
    expect(shoppingErrors(fn () => app(SetDefaultShoppingList::class)($this->creator, $market)))
        ->toBe(['list' => ['An archived list cannot be the default.']])
        ->and($market->refresh()->is_default)->toBeFalse();
});

test('items are appended at the end', function () {
    $list = app(CreateShoppingList::class)($this->creator, ['name' => 'Groceries']);
    $other = ShoppingList::factory()->create();
    ShoppingListItem::factory()->for($other, 'list')->create(['position' => 40]);

    $milk = app(AddShoppingListItem::class)($this->creator, $list, ['name' => ' Milk ', 'quantity_note' => ' 2 L ']);
    $bread = app(AddShoppingListItem::class)($this->member, $list, ['name' => 'Bread', 'quantity_note' => '  ']);
    $eggs = app(AddShoppingListItem::class)($this->creator, $list, ['name' => 'Eggs']);

    expect([$milk->position, $bread->position, $eggs->position])->toBe([0, 1, 2])
        ->and($milk->name)->toBe('Milk')
        ->and($milk->quantity_note)->toBe('2 L')
        ->and($bread->quantity_note)->toBeNull()
        ->and($bread->created_by_id)->toBe($this->member->id)
        ->and($list->items()->pluck('id')->all())->toBe([$milk->id, $bread->id, $eggs->id]);

    // Removing one does not reuse its place.
    app(RemoveShoppingListItem::class)($this->member, $bread);
    $rice = app(AddShoppingListItem::class)($this->member, $list, ['name' => 'Rice']);

    expect($rice->position)->toBe(3)
        ->and(ShoppingListItem::query()->whereKey($bread->id)->exists())->toBeFalse();

    // The name is required; the note is optional; both up to 255.
    expect(shoppingErrors(fn () => app(AddShoppingListItem::class)($this->creator, $list, ['name' => ''])))->toHaveKey('name')
        ->and(shoppingErrors(fn () => app(AddShoppingListItem::class)($this->creator, $list, ['name' => 'Tea', 'quantity_note' => str_repeat('x', 256)])))->toHaveKey('quantity_note');

    // Editing keeps the place.
    app(UpdateShoppingListItem::class)($this->member, $milk, ['name' => 'Oat milk', 'quantity_note' => '1 L']);

    expect($milk->refresh()->only(['name', 'quantity_note', 'position']))->toBe(['name' => 'Oat milk', 'quantity_note' => '1 L', 'position' => 0])
        ->and(shoppingErrors(fn () => app(UpdateShoppingListItem::class)($this->member, $milk, ['name' => ' '])))->toHaveKey('name');
});

test('users without shopping module are forbidden', function () {
    $outsider = User::factory()->withModules([Module::REMINDERS])->create();
    $list = ShoppingList::factory()->for($this->creator, 'createdBy')->create();
    $item = ShoppingListItem::factory()->for($list, 'list')->create();

    $calls = [
        fn () => app(CreateShoppingList::class)($outsider, ['name' => 'Mine']),
        fn () => app(RenameShoppingList::class)($outsider, $list, ['name' => 'Mine']),
        fn () => app(ArchiveShoppingList::class)($outsider, $list),
        fn () => app(UnarchiveShoppingList::class)($outsider, $list),
        fn () => app(DeleteShoppingList::class)($outsider, $list),
        fn () => app(SetDefaultShoppingList::class)($outsider, $list),
        fn () => app(AddShoppingListItem::class)($outsider, $list, ['name' => 'Milk']),
        fn () => app(UpdateShoppingListItem::class)($outsider, $item, ['name' => 'Milk']),
        fn () => app(RemoveShoppingListItem::class)($outsider, $item),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(AuthorizationException::class);
    }

    expect(ShoppingList::query()->count())->toBe(1)
        ->and($list->refresh()->archived_at)->toBeNull()
        ->and($list->trashed())->toBeFalse()
        ->and($list->is_default)->toBeFalse()
        ->and(ShoppingListItem::query()->count())->toBe(1);
});
