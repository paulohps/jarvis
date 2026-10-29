<?php

namespace Database\Factories;

use App\Models\Issuer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issuer>
 */
class IssuerFactory extends Factory
{
    /**
     * A Brazilian issuer, in the household workspace unless told another.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fn (): int => WorkspaceFactory::household()->id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'postal_code' => fake()->numerify('#####-###'),
            'street' => fake()->streetName().', '.fake()->buildingNumber(),
            'district' => fake()->word(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['SP', 'RJ', 'MG', 'PR', 'SC', 'RS']),
            'created_by_id' => User::factory(),
        ];
    }
}
