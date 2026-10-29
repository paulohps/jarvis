<?php

namespace Database\Factories;

use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Weekday;
use App\Support\FriendlyId;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recurrence>
 */
class RecurrenceFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A daily rule at 09:00 São Paulo time, owned by a new reminder and due
     * tomorrow.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedulable_type' => 'reminder',
            'schedulable_id' => fn (): int => self::newReminderId(),
            'recurrence_frequency_id' => fn (): int => self::frequencyId(RecurrenceFrequency::DAILY),
            'time_of_day' => '09:00',
            'day_of_month' => null,
            'is_last_day_of_month' => false,
            'cron_expression' => null,
            'timezone' => 'America/Sao_Paulo',
            'starts_at' => now()->startOfDay(),
            'ends_at' => null,
            'next_run_at' => now()->addDay()->startOfDay()->setTime(12, 0),
            'last_run_at' => null,
            'is_paused' => false,
        ];
    }

    /**
     * A single run at `starts_at`.
     */
    public function once(): static
    {
        return $this->frequency(RecurrenceFrequency::ONCE, ['time_of_day' => null])
            ->state(function (array $attributes) {
                $runAt = now()->addDay()->startOfHour();

                return ['starts_at' => $runAt, 'next_run_at' => $runAt];
            });
    }

    public function daily(): static
    {
        return $this->frequency(RecurrenceFrequency::DAILY);
    }

    /**
     * On the given ISO weekdays (Monday by default).
     *
     * @param  list<string>  $weekdaySlugs
     */
    public function weekly(array $weekdaySlugs = [Weekday::MONDAY]): static
    {
        return $this->frequency(RecurrenceFrequency::WEEKLY)
            ->afterCreating(function (Recurrence $recurrence) use ($weekdaySlugs): void {
                $recurrence->weekdays()->sync(array_map(
                    fn (string $slug): int => static::lookupId(Weekday::class, $slug),
                    $weekdaySlugs,
                ));
            });
    }

    /**
     * On day N of every month (clamped to the last day of shorter months).
     */
    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->frequency(RecurrenceFrequency::MONTHLY, ['day_of_month' => $dayOfMonth]);
    }

    public function lastDayOfMonth(): static
    {
        return $this->frequency(RecurrenceFrequency::MONTHLY, ['is_last_day_of_month' => true]);
    }

    /**
     * A custom cron expression, read in the rule's timezone.
     */
    public function cron(string $expression = '0 9 * * 1-5'): static
    {
        return $this->frequency(RecurrenceFrequency::CUSTOM_CRON, ['time_of_day' => null, 'cron_expression' => $expression]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_paused' => true,
        ]);
    }

    /**
     * Switch to a frequency, clearing the fields of the other presets.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function frequency(string $slug, array $attributes = []): static
    {
        return $this->state(fn (array $current) => [
            'recurrence_frequency_id' => self::frequencyId($slug),
            'time_of_day' => '09:00',
            'day_of_month' => null,
            'is_last_day_of_month' => false,
            'cron_expression' => null,
            ...$attributes,
        ]);
    }

    private static function frequencyId(string $slug): int
    {
        return static::lookupId(RecurrenceFrequency::class, $slug);
    }

    /**
     * The owner: a new reminder (Reminder gets its own factory with the
     * reminders module).
     */
    private static function newReminderId(): int
    {
        $reminder = (new Reminder)->forceFill([
            'friendly_id' => FriendlyId::generate('rem'),
            'workspace_id' => WorkspaceFactory::household()->id,
            'title' => fake()->sentence(3),
            'created_by_id' => User::factory()->create()->id,
        ]);
        $reminder->save();

        return $reminder->id;
    }
}
