<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderOccurrence>
 */
class ReminderOccurrenceFactory extends Factory
{
    /**
     * An open occurrence of a new reminder, due tomorrow (`pending`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reminder_id' => Reminder::factory(),
            'due_at' => now()->addDay()->startOfHour(),
            'notified_at' => null,
            'completed_at' => null,
            'completed_by_id' => null,
        ];
    }

    /**
     * Completed now by a new user.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
            'completed_by_id' => User::factory(),
        ]);
    }

    /**
     * Open and past due, already notified.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => now()->subHour(),
            'notified_at' => now()->subHour(),
            'completed_at' => null,
            'completed_by_id' => null,
        ]);
    }

    /**
     * Without a date: always `pending` until completed.
     */
    public function undated(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => null,
        ]);
    }
}
