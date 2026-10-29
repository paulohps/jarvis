<?php

namespace Database\Factories;

use App\Models\GoogleCalendarAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleCalendarAccount>
 */
class GoogleCalendarAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'refresh_token' => 'refresh-'.fake()->uuid(),
            'last_error' => null,
            'failed_at' => null,
        ];
    }
}
