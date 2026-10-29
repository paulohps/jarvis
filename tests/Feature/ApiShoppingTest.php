<?php

use App\Events\ShoppingListChanged;
use App\Livewire\Shopping\ShoppingListIndex;
use App\Livewire\Shopping\ShoppingListShow;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::SHOPPING])->create();
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
});

test('api shopping endpoints use the same actions as livewire', function () {
    // The same 422 as the web form, for the list name and the item name.
    $this->withToken($this->token)
        ->postJson('/api/v1/shopping/lists', ['name' => str_repeat('a', 101)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    Livewire::actingAs($this->user)
        ->test(ShoppingListIndex::class)
        ->set('name', str_repeat('a', 101))
        ->call('save')
        ->assertHasErrors(['name']);

    expect(ShoppingList::query()->count())->toBe(0);

    Event::fake([ShoppingListChanged::class]);

    $created = $this->withToken($this->token)
        ->postJson('/api/v1/shopping/lists', ['name' => '  Groceries  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Groceries')
        ->assertJsonPath('data.is_archived', false)
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.items_count', 0);

    $list = ShoppingList::query()->sole();

    expect($created->json('data.friendly_id'))->toBe($list->friendly_id)
        ->and($list->created_by_id)->toBe($this->user->id);

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$list->friendly_id}/items", ['name' => ' '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    Livewire::actingAs($this->user)
        ->test(ShoppingListShow::class, ['list' => $list])
        ->set('itemName', ' ')
        ->call('addItem')
        ->assertHasErrors(['name']);

    $milk = $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$list->friendly_id}/items", ['name' => 'Milk', 'quantity_note' => '2 L'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Milk')
        ->assertJsonPath('data.quantity_note', '2 L')
        ->assertJsonPath('data.list_id', $list->friendly_id)
        ->assertJsonPath('data.is_checked', false)
        ->json('data.friendly_id');

    $bread = ShoppingListItem::factory()->for($list, 'list')->for($this->user, 'createdBy')->create(['name' => 'Bread', 'position' => 5]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/shopping/items/{$milk}", ['name' => 'Whole milk', 'quantity_note' => ''])
        ->assertOk()
        ->assertJsonPath('data.name', 'Whole milk')
        ->assertJsonPath('data.quantity_note', null);

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/items/{$milk}/toggle", ['checked' => true])
        ->assertOk()
        ->assertJsonPath('data.is_checked', true)
        ->assertJsonPath('data.checked_by.friendly_id', $this->user->friendly_id);

    // Checked items go to the end, as on the web.
    $this->withToken($this->token)
        ->getJson("/api/v1/shopping/lists/{$list->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.items.*.name', ['Bread', 'Whole milk'])
        ->assertJsonPath('data.unchecked_items_count', 1);

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$list->friendly_id}/clear-checked")
        ->assertOk()
        ->assertJsonPath('meta.removed', 1)
        ->assertJsonPath('data.items.*.name', ['Bread']);

    $this->withToken($this->token)
        ->patchJson("/api/v1/shopping/lists/{$list->friendly_id}", ['name' => 'Market'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Market');

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$list->friendly_id}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    // An archived list is read only, with the same error as the web.
    $this->withToken($this->token)
        ->deleteJson("/api/v1/shopping/items/{$bread->friendly_id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['list']);

    $this->withToken($this->token)->getJson('/api/v1/shopping/lists')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->token)
        ->getJson('/api/v1/shopping/lists?filter=archived')
        ->assertOk()
        ->assertJsonPath('data.*.friendly_id', [$list->friendly_id]);

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$list->friendly_id}/unarchive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', false);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/shopping/items/{$bread->friendly_id}")
        ->assertNoContent();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/shopping/lists/{$list->friendly_id}")
        ->assertNoContent();

    expect($list->fresh()->trashed())->toBeTrue();

    $this->withToken($this->token)->getJson("/api/v1/shopping/lists/{$list->friendly_id}")->assertNotFound();

    // Every mutation broadcast the same change as the web.
    $changes = [];
    Event::assertDispatched(ShoppingListChanged::class, function (ShoppingListChanged $event) use (&$changes, $list): bool {
        $changes[] = $event->change;

        return $event->listId === $list->friendly_id;
    });

    expect($changes)->toBe([
        ShoppingListChanged::LIST_CREATED,
        ShoppingListChanged::ITEM_ADDED,
        ShoppingListChanged::ITEM_UPDATED,
        ShoppingListChanged::ITEM_TOGGLED,
        ShoppingListChanged::CHECKED_CLEARED,
        ShoppingListChanged::LIST_RENAMED,
        ShoppingListChanged::LIST_ARCHIVED,
        ShoppingListChanged::LIST_UNARCHIVED,
        ShoppingListChanged::ITEM_REMOVED,
        ShoppingListChanged::LIST_DELETED,
    ]);
});

test('api toggle applies last write wins', function () {
    $list = ShoppingList::factory()->for($this->user, 'createdBy')->create();
    $item = ShoppingListItem::factory()->for($list, 'list')->for($this->user, 'createdBy')->create();

    // Checked online at 12:05.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:05', 'UTC'));

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/items/{$item->friendly_id}/toggle", ['checked' => true])
        ->assertOk()
        ->assertJsonPath('data.is_checked', true)
        ->assertJsonPath('data.client_updated_at', '2026-10-05T12:05:00+00:00');

    // An uncheck made offline at 12:01 arrives later: the newer check wins.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:10', 'UTC'));

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/items/{$item->friendly_id}/toggle", [
            'checked' => false,
            'client_updated_at' => '2026-10-05T09:01:00-03:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_checked', true)
        ->assertJsonPath('data.client_updated_at', '2026-10-05T12:05:00+00:00');

    // An uncheck made offline at 12:08 is newer: it wins.
    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/items/{$item->friendly_id}/toggle", [
            'checked' => false,
            'client_updated_at' => '2026-10-05T12:08:00Z',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_checked', false)
        ->assertJsonPath('data.client_updated_at', '2026-10-05T12:08:00+00:00');

    expect($item->fresh()->checked_at)->toBeNull();

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/items/{$item->friendly_id}/toggle", ['client_updated_at' => 'yesterday-ish'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['checked', 'client_updated_at']);
});

test('api sets and unsets the default list', function () {
    $market = ShoppingList::factory()->asDefault()->create(['name' => 'Market']);
    $pharmacy = ShoppingList::factory()->create(['name' => 'Pharmacy']);

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$pharmacy->friendly_id}/default")
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    // The previous default stops being it.
    $this->withToken($this->token)
        ->getJson('/api/v1/shopping/lists')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Market')
        ->assertJsonPath('data.0.is_default', false)
        ->assertJsonPath('data.1.is_default', true);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/shopping/lists/{$pharmacy->friendly_id}/default")
        ->assertOk()
        ->assertJsonPath('data.is_default', false);

    expect(ShoppingList::defaultIn(WorkspaceFactory::household()))->toBeNull();

    // An archived list cannot be the default.
    $market->update(['archived_at' => now()]);

    $this->withToken($this->token)
        ->postJson("/api/v1/shopping/lists/{$market->friendly_id}/default")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['list']);
});

test('api shopping requires module', function () {
    $list = ShoppingList::factory()->create();
    $item = ShoppingListItem::factory()->for($list, 'list')->create();
    $outsider = User::factory()->create();
    $token = $outsider->createToken('Pixel')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/shopping/lists')->assertForbidden()->assertJsonStructure(['message']);
    $this->withToken($token)->postJson('/api/v1/shopping/lists', ['name' => 'Mine'])->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/shopping/lists/{$list->friendly_id}/items", ['name' => 'Eggs'])->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/shopping/items/{$item->friendly_id}/toggle", ['checked' => true])->assertForbidden();
    $this->withToken($token)->deleteJson("/api/v1/shopping/lists/{$list->friendly_id}")->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/shopping/lists/{$list->friendly_id}/default")->assertForbidden();

    expect(ShoppingList::query()->count())->toBe(1)
        ->and($item->fresh()->checked_at)->toBeNull()
        ->and($list->fresh()->trashed())->toBeFalse();

    app('auth')->forgetGuards();

    $this->withoutToken()->getJson('/api/v1/shopping/lists')->assertUnauthorized();
});
