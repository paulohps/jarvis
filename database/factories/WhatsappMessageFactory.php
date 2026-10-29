<?php

namespace Database\Factories;

use App\Models\Recurrence;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappMessage>
 */
class WhatsappMessageFactory extends Factory
{
    /**
     * A message of a new user from their own open account, without targets.
     * Created with a `once` recurrence unless one was given through `has()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'whatsapp_account_id' => fn (array $attributes) => WhatsappAccount::factory()->open()->state(['user_id' => $attributes['user_id']]),
            'body' => 'Hi {% recipient.name %}, '.fake()->sentence(),
            'cancelled_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (WhatsappMessage $message): void {
            if ($message->recurrence()->doesntExist()) {
                Recurrence::factory()->once()->for($message, 'schedulable')->create();
            }
        });
    }

    /**
     * A single run, tomorrow.
     */
    public function once(): static
    {
        return $this->has(Recurrence::factory()->once(), 'recurrence');
    }

    /**
     * Every day at 09:00.
     */
    public function recurring(): static
    {
        return $this->has(Recurrence::factory()->daily(), 'recurrence');
    }

    /**
     * A `once` message cancelled before it ran: its rule has no next run.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => now(),
        ])->has(Recurrence::factory()->once()->state(['next_run_at' => null]), 'recurrence');
    }

    /**
     * A poll of one answer, its question the body.
     */
    public function poll(): static
    {
        return $this->state(fn (array $attributes) => [
            'body' => 'Lunch?',
            'poll' => ['name' => 'Lunch?', 'options' => ['Yes', 'No'], 'selectable_count' => 1],
        ]);
    }

    /**
     * Sent to `$count` contacts of the message's account.
     */
    public function withTargets(int $count = 1): static
    {
        return $this->afterCreating(function (WhatsappMessage $message) use ($count): void {
            $message->targets()->attach(
                WhatsappTarget::factory()->count($count)->create(['whatsapp_account_id' => $message->whatsapp_account_id]),
            );
        });
    }
}
