<?php

namespace Database\Factories;

use App\Models\Recurrence;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Models\Weekday;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListSchedule>
 */
class ShoppingListScheduleFactory extends Factory
{
    /**
     * Closes by itself and carries the items left unchecked; every Friday
     * at 22:00 unless a recurrence is given. In the household workspace.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Market', 'Pharmacy', 'Bakery']),
            'next_list_content' => 'pending',
            'closes_automatically' => true,
            'waits_for_close' => false,
            'template_items' => null,
            'created_by_id' => User::factory(),
            'workspace_id' => fn (): int => WorkspaceFactory::household()->id,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ShoppingListSchedule $schedule): void {
            if ($schedule->recurrence()->doesntExist()) {
                Recurrence::factory()->weekly([Weekday::FRIDAY])->for($schedule, 'schedulable')->create(['time_of_day' => '22:00']);
            }
        });
    }

    /**
     * A schedule of `$workspace`.
     */
    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $workspace->id,
        ]);
    }

    /**
     * Closed by hand only.
     */
    public function manual(bool $waitsForClose = false): static
    {
        return $this->state(fn (array $attributes) => [
            'closes_automatically' => false,
            'waits_for_close' => $waitsForClose,
        ]);
    }
}
