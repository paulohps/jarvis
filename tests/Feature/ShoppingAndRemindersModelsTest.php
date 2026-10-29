<?php

use App\Models\Contracts\Schedulable;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\ShoppingList;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceFactory;

test('reminder occurrence scopes classify pending overdue and completed', function () {
    $this->freezeTime();
    $completer = User::factory()->create();
    $reminder = Reminder::query()->create(['title' => 'Water the plants', 'created_by_id' => User::factory()->create()->id, 'workspace_id' => WorkspaceFactory::household()->id]);

    $occurrences = collect([
        'undated' => ['due_at' => null],
        'future' => ['due_at' => now()->addHour()],
        'due now' => ['due_at' => now()],
        'past' => ['due_at' => now()->subMinute()],
        'completed past' => ['due_at' => now()->subDay(), 'completed_at' => now(), 'completed_by_id' => $completer->id],
        'completed future' => ['due_at' => now()->addDay(), 'completed_at' => now(), 'completed_by_id' => $completer->id],
        'completed undated' => ['due_at' => null, 'completed_at' => now(), 'completed_by_id' => $completer->id],
    ])->map(fn (array $attributes) => $reminder->occurrences()->create($attributes)->id);

    $idsOf = fn (string $scope): array => ReminderOccurrence::query()->{$scope}()->pluck('id')->sort()->values()->all();
    $expected = fn (array $labels): array => $occurrences->only($labels)->sort()->values()->all();

    expect($idsOf('pending'))->toBe($expected(['undated', 'future', 'due now']))
        ->and($idsOf('overdue'))->toBe($expected(['past']))
        ->and($idsOf('completed'))->toBe($expected(['completed past', 'completed future', 'completed undated']))
        ->and(count($idsOf('pending')) + count($idsOf('overdue')) + count($idsOf('completed')))->toBe($occurrences->count());
});

test('shopping list items order checked last', function () {
    $creator = User::factory()->create();
    $list = ShoppingList::query()->create(['workspace_id' => WorkspaceFactory::household()->id, 'name' => 'Groceries', 'created_by_id' => $creator->id]);

    $item = fn (string $name, int $position, ?CarbonInterface $checkedAt = null) => $list->items()->create([
        'name' => $name,
        'position' => $position,
        'checked_at' => $checkedAt,
        'checked_by_id' => $checkedAt ? $creator->id : null,
        'created_by_id' => $creator->id,
    ]);

    $item('Milk', 1, now()->subMinutes(5));
    $item('Bread', 2);
    $item('Eggs', 0, now()->subMinutes(10));
    $item('Rice', 3);
    $item('Coffee', 0);

    expect($list->items->pluck('name')->all())->toBe(['Coffee', 'Bread', 'Rice', 'Eggs', 'Milk'])
        ->and($list->items->first()->list->is($list))->toBeTrue()
        ->and($list->items->last()->checkedBy->is($creator))->toBeTrue()
        ->and($list->items->last()->isChecked())->toBeTrue()
        ->and($list->items->first()->createdBy->is($creator))->toBeTrue();
});

test('archived shopping lists are left out of not archived', function () {
    $creator = User::factory()->create();
    $active = ShoppingList::query()->create(['workspace_id' => WorkspaceFactory::household()->id, 'name' => 'Groceries', 'created_by_id' => $creator->id]);
    ShoppingList::query()->create(['workspace_id' => WorkspaceFactory::household()->id, 'name' => 'Party', 'archived_at' => now(), 'created_by_id' => $creator->id]);

    expect(ShoppingList::query()->notArchived()->pluck('id')->all())->toBe([$active->id])
        ->and($active->friendly_id)->toStartWith('shl_')
        ->and($active->createdBy->is($creator))->toBeTrue();
});

test('reminder relations are navigable and it is schedulable', function () {
    $creator = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    $reminder = Reminder::query()->create(['title' => 'Pay rent', 'created_by_id' => $creator->id, 'workspace_id' => WorkspaceFactory::household()->id]);
    $occurrence = $reminder->occurrences()->create(['due_at' => now()->addDay()]);

    $reminder->delete();

    expect($reminder)->toBeInstanceOf(Schedulable::class)
        ->and($reminder->ownerTimezone())->toBe('Asia/Tokyo')
        ->and($reminder->createdBy->is($creator))->toBeTrue()
        ->and($reminder->occurrences->first()->is($occurrence))->toBeTrue()
        ->and($occurrence->fresh()->reminder->trashed())->toBeTrue()
        ->and($occurrence->completedBy)->toBeNull()
        ->and($occurrence->friendly_id)->toStartWith('remo_');
});
