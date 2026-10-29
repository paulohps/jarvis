<?php

namespace Database\Factories;

use App\Models\Recurrence;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * A bare reminder, without occurrences or recurrence: use a state for a
     * usable one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'note' => fake()->optional()->sentence(),
            'workspace_id' => fn (): int => WorkspaceFactory::household()->id,
            'created_by_id' => User::factory(),
        ];
    }

    /**
     * Due once, tomorrow: a `once` recurrence and its occurrence.
     */
    public function dated(): static
    {
        $dueAt = now()->addDay()->startOfHour();

        return $this
            ->has(Recurrence::factory()->once()->state(['starts_at' => $dueAt, 'next_run_at' => $dueAt]), 'recurrence')
            ->has(ReminderOccurrence::factory()->state(['due_at' => $dueAt]), 'occurrences');
    }

    /**
     * A task without date nor recurrence: a single undated occurrence.
     */
    public function undated(): static
    {
        return $this->has(ReminderOccurrence::factory()->undated(), 'occurrences');
    }

    /**
     * Every day at 09:00, with the occurrence of its next run.
     */
    public function recurring(): static
    {
        $nextRunAt = now()->addDay()->startOfDay()->setTime(12, 0);

        return $this
            ->has(Recurrence::factory()->daily()->state(['next_run_at' => $nextRunAt]), 'recurrence')
            ->has(ReminderOccurrence::factory()->state(['due_at' => $nextRunAt]), 'occurrences');
    }
}
