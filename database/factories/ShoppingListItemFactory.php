<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListItem>
 */
class ShoppingListItemFactory extends Factory
{
    /**
     * An unchecked item of a new list.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'created_by_id' => User::factory(),
            'name' => fake()->randomElement(['Milk', 'Bread', 'Eggs', 'Rice', 'Coffee', 'Apples', 'Soap']),
            'quantity_note' => fake()->optional()->randomElement(['2 L', '1 kg', '12 units', '500 g']),
            'position' => fake()->numberBetween(0, 50),
            'checked_at' => null,
            'checked_by_id' => null,
            'client_updated_at' => null,
        ];
    }

    /**
     * Checked by the user who added it.
     */
    public function checked(): static
    {
        return $this->state(fn (array $attributes) => [
            'checked_at' => now(),
            'checked_by_id' => fn (array $attributes) => $attributes['created_by_id'],
        ]);
    }
}
