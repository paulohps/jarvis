<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingList>
 */
class ShoppingListFactory extends Factory
{
    /**
     * An active, empty list of the household workspace — or of its
     * schedule's workspace, when it has one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Groceries', 'Pharmacy', 'Bakery', 'Hardware store']),
            'archived_at' => null,
            'is_default' => false,
            'created_by_id' => User::factory(),
            'workspace_id' => fn (array $attributes): int => is_int($attributes['shopping_list_schedule_id'] ?? null)
                ? ShoppingListSchedule::withTrashed()->findOrFail($attributes['shopping_list_schedule_id'])->workspace_id
                : WorkspaceFactory::household()->id,
        ];
    }

    /**
     * A list of `$workspace`.
     */
    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Its workspace's default list for voice commands.
     */
    public function asDefault(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
