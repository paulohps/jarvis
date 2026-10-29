<?php

use App\Actions\Shopping\CreateShoppingListSchedule;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Models\Weekday;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    // 09:00 on Monday, October 5, in São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::SHOPPING])->create(['timezone' => 'America/Sao_Paulo']);
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
    $this->weekly = ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => [Weekday::FRIDAY], 'time_of_day' => '22:00'];
});

test('api creates a recurring list with its first list, as the web', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/shopping/schedules', ['name' => 'Market', 'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '22:00']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence.weekdays']);

    $scheduleId = $this->withToken($this->token)
        ->postJson('/api/v1/shopping/schedules', [
            'name' => ' Market ',
            'recurrence' => $this->weekly,
            'next_list_content' => 'template_and_pending',
            'closes_automatically' => false,
            'waits_for_close' => true,
            'template_items' => ['Milk', ' ', 'Bread'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Market')
        ->assertJsonPath('data.next_list_content', 'template_and_pending')
        ->assertJsonPath('data.closes_automatically', false)
        ->assertJsonPath('data.waits_for_close', true)
        ->assertJsonPath('data.template_items', ['Milk', 'Bread'])
        ->assertJsonPath('data.recurrence.weekdays', ['friday'])
        // Friday at 22:00 in São Paulo.
        ->assertJsonPath('data.open_list.due_at', '2026-10-10T01:00:00+00:00')
        ->json('data.friendly_id');

    $listId = $this->withToken($this->token)->getJson("/api/v1/shopping/schedules/{$scheduleId}")->json('data.open_list.friendly_id');

    $this->withToken($this->token)->getJson('/api/v1/shopping/lists')
        ->assertJsonPath('data.0.friendly_id', $listId)
        ->assertJsonPath('data.0.is_recurring', true)
        ->assertJsonPath('data.0.schedule_id', $scheduleId);
});

test('api edits a recurring list whole and stops it, keeping its lists', function () {
    $schedule = app(CreateShoppingListSchedule::class)($this->user, ['name' => 'Market', 'recurrence' => $this->weekly, 'closes_automatically' => false, 'waits_for_close' => true]);
    $list = $schedule->openList();

    // A list that closes by itself never holds the next one back.
    $this->withToken($this->token)
        ->patchJson("/api/v1/shopping/schedules/{$schedule->friendly_id}", [
            'name' => 'Groceries',
            'recurrence' => [...$this->weekly, 'weekdays' => [Weekday::SATURDAY]],
            'closes_automatically' => true,
            'waits_for_close' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Groceries')
        ->assertJsonPath('data.waits_for_close', false)
        // The open list moves to the new rule: Saturday at 22:00.
        ->assertJsonPath('data.open_list.friendly_id', $list->friendly_id)
        ->assertJsonPath('data.open_list.due_at', '2026-10-11T01:00:00+00:00');

    $this->withToken($this->token)->getJson('/api/v1/shopping/schedules')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Groceries');

    $this->withToken($this->token)->deleteJson("/api/v1/shopping/schedules/{$schedule->friendly_id}")->assertNoContent();
    $this->withToken($this->token)->getJson("/api/v1/shopping/schedules/{$schedule->friendly_id}")->assertNotFound();

    expect(ShoppingListSchedule::withTrashed()->sole()->trashed())->toBeTrue()
        ->and($list->fresh())->not->toBeNull();
});

test('api recurring lists require the shopping module', function () {
    $schedule = app(CreateShoppingListSchedule::class)($this->user, ['name' => 'Market', 'recurrence' => $this->weekly]);
    $token = User::factory()->create()->createToken('Pixel')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/shopping/schedules')->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/shopping/schedules', ['name' => 'Mine', 'recurrence' => $this->weekly])->assertForbidden();
    $this->withToken($token)->deleteJson("/api/v1/shopping/schedules/{$schedule->friendly_id}")->assertForbidden();

    expect(ShoppingListSchedule::query()->count())->toBe(1);
});
