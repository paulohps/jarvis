<?php

use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\ArchiveShoppingList;
use App\Actions\Shopping\ClearCheckedItems;
use App\Actions\Shopping\CreateShoppingList;
use App\Actions\Shopping\DeleteShoppingList;
use App\Actions\Shopping\RemoveShoppingListItem;
use App\Actions\Shopping\RenameShoppingList;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Actions\Shopping\UnarchiveShoppingList;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Events\ShoppingListChanged;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::SHOPPING])->create();
    $this->member = User::factory()->withModules([Module::SHOPPING])->create();
    $this->list = ShoppingList::factory()->for($this->user, 'createdBy')->create();
});

test('toggling broadcasts to the list channel', function () {
    $item = ShoppingListItem::factory()->for($this->list, 'list')->for($this->user, 'createdBy')->create();

    Event::fake([ShoppingListChanged::class]);

    $checked = app(ToggleShoppingListItem::class)($this->member, $item, true);

    expect($checked->checked_at->equalTo(now()))->toBeTrue()
        ->and($checked->checked_by_id)->toBe($this->member->id)
        ->and($checked->client_updated_at->equalTo(now()))->toBeTrue();

    Event::assertDispatched(ShoppingListChanged::class, function (ShoppingListChanged $event) use ($item): bool {
        $channels = array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());

        return $channels === ['private-shopping-lists.'.$this->list->friendly_id]
            && $event->broadcastAs() === 'shopping-list.changed'
            && $event->broadcastWith() === ['list_id' => $this->list->friendly_id, 'change' => ShoppingListChanged::ITEM_TOGGLED, 'item_id' => $item->friendly_id];
    });

    $this->travel(5)->seconds();
    $unchecked = app(ToggleShoppingListItem::class)($this->user, $item, false);

    expect($unchecked->checked_at)->toBeNull()
        ->and($unchecked->checked_by_id)->toBeNull();

    Event::assertDispatchedTimes(ShoppingListChanged::class, 2);

    // Every other change of the list or its items is broadcast too.
    $list = app(CreateShoppingList::class)($this->user, ['name' => 'Bakery']);
    $bread = app(AddShoppingListItem::class)($this->user, $list, ['name' => 'Bread']);
    app(UpdateShoppingListItem::class)($this->user, $bread, ['name' => 'Rye bread']);
    app(RemoveShoppingListItem::class)($this->user, $bread);
    app(RenameShoppingList::class)($this->user, $list, ['name' => 'Bakery shop']);
    app(ArchiveShoppingList::class)($this->user, $list);
    app(UnarchiveShoppingList::class)($this->user, $list);
    app(DeleteShoppingList::class)($this->user, $list);

    $changes = collect(Event::dispatched(ShoppingListChanged::class))
        ->map(fn (array $arguments) => $arguments[0])
        ->filter(fn (ShoppingListChanged $event): bool => $event->listId === $list->friendly_id)
        ->map(fn (ShoppingListChanged $event): string => $event->change)
        ->values()
        ->all();

    expect($changes)->toBe([
        ShoppingListChanged::LIST_CREATED,
        ShoppingListChanged::ITEM_ADDED,
        ShoppingListChanged::ITEM_UPDATED,
        ShoppingListChanged::ITEM_REMOVED,
        ShoppingListChanged::LIST_RENAMED,
        ShoppingListChanged::LIST_ARCHIVED,
        ShoppingListChanged::LIST_UNARCHIVED,
        ShoppingListChanged::LIST_DELETED,
    ]);
});

test('older offline write does not override newer state', function () {
    $item = ShoppingListItem::factory()->for($this->list, 'list')->for($this->user, 'createdBy')->create();

    // Checked online at 12:00.
    app(ToggleShoppingListItem::class)($this->user, $item, true);

    Event::fake([ShoppingListChanged::class]);

    // An uncheck made offline at 11:58 arrives later: it is ignored.
    $this->travel(10)->minutes();
    $result = app(ToggleShoppingListItem::class)($this->member, $item, false, CarbonImmutable::parse('2026-10-05 11:58', 'UTC'));

    expect($result->checked_at)->not->toBeNull()
        ->and($result->checked_by_id)->toBe($this->user->id)
        ->and($result->client_updated_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');

    Event::assertNotDispatched(ShoppingListChanged::class);

    // An uncheck made offline at 12:05, newer than the stored write, wins.
    $result = app(ToggleShoppingListItem::class)($this->member, $item, false, CarbonImmutable::parse('2026-10-05 12:05', 'UTC'));

    expect($result->checked_at)->toBeNull()
        ->and($result->client_updated_at->toIso8601String())->toBe('2026-10-05T12:05:00+00:00');

    Event::assertDispatchedTimes(ShoppingListChanged::class, 1);

    // A device clock ahead of the server counts as now, so it cannot lock the item.
    $result = app(ToggleShoppingListItem::class)($this->member, $item, true, CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    expect($result->client_updated_at->toIso8601String())->toBe('2026-10-05T12:10:00+00:00');

    $this->travel(1)->minutes();
    $result = app(ToggleShoppingListItem::class)($this->user, $item, false);

    expect($result->checked_at)->toBeNull();

    // Without the module, nothing changes.
    $outsider = User::factory()->create();

    expect(fn () => app(ToggleShoppingListItem::class)($outsider, $item, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ClearCheckedItems::class)($outsider, $this->list))->toThrow(AuthorizationException::class);
});

test('clear checked removes only checked items', function () {
    $milk = ShoppingListItem::factory()->for($this->list, 'list')->for($this->user, 'createdBy')->create(['position' => 0]);
    $bread = ShoppingListItem::factory()->for($this->list, 'list')->for($this->user, 'createdBy')->checked()->create(['position' => 1]);
    $eggs = ShoppingListItem::factory()->for($this->list, 'list')->for($this->user, 'createdBy')->create(['position' => 2]);
    $otherList = ShoppingListItem::factory()->checked()->create();

    app(ToggleShoppingListItem::class)($this->member, $eggs, true);

    Event::fake([ShoppingListChanged::class]);

    expect(app(ClearCheckedItems::class)($this->member, $this->list))->toBe(2)
        ->and($this->list->items()->pluck('id')->all())->toBe([$milk->id])
        ->and(ShoppingListItem::query()->whereKey($otherList->id)->exists())->toBeTrue()
        ->and(ShoppingListItem::query()->whereKey($bread->id)->exists())->toBeFalse();

    Event::assertDispatched(ShoppingListChanged::class, fn (ShoppingListChanged $event): bool => $event->listId === $this->list->friendly_id
        && $event->change === ShoppingListChanged::CHECKED_CLEARED);

    // Nothing checked left: nothing removed and nothing broadcast.
    expect(app(ClearCheckedItems::class)($this->member, $this->list))->toBe(0);

    Event::assertDispatchedTimes(ShoppingListChanged::class, 1);
});

test('list channel authorizes only users with the shopping module', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');

    $channel = 'private-'.ShoppingListChanged::channelFor($this->list->friendly_id);
    $auth = fn (User $user, ?string $channelName = null) => $this->actingAs($user)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channelName ?? $channel]);

    // Any member of the household with the module, not only the creator.
    $auth($this->member)->assertOk()->assertJsonStructure(['auth']);
    $auth($this->user)->assertOk();

    $auth(User::factory()->withModules([Module::REMINDERS])->create())->assertForbidden();

    // A list that does not exist (or was deleted) has no channel.
    $auth($this->member, 'private-'.ShoppingListChanged::channelFor('shl_missing'))->assertForbidden();

    $this->list->delete();
    $auth($this->member)->assertForbidden();
});
