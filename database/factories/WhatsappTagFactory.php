<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappTag>
 */
class WhatsappTagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->randomElement(array_keys(WhatsappTarget::PRIORITY_COLORS)),
        ];
    }
}
