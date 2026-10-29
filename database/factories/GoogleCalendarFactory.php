<?php

namespace Database\Factories;

use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleCalendar>
 */
class GoogleCalendarFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_calendar_account_id' => GoogleCalendarAccount::factory(),
            'google_id' => fake()->unique()->safeEmail(),
            'name' => fake()->words(2, true),
            'color' => '#039be5',
            'is_primary' => false,
            'is_visible' => true,
        ];
    }
}
