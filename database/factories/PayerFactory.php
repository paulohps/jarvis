<?php

namespace Database\Factories;

use App\Models\Payer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payer>
 */
class PayerFactory extends Factory
{
    /**
     * A company with a multiline "Bill to" address, in the household
     * workspace unless told another.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fn (): int => WorkspaceFactory::household()->id,
            'name' => fake()->company(),
            'address' => fake()->streetAddress()."\n".fake()->city().' '.fake()->postcode(),
            'created_by_id' => User::factory(),
        ];
    }
}
