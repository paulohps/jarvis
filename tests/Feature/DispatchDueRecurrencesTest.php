<?php

use App\Jobs\RunScheduledSlot;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:30', 'UTC'));
});

/**
 * A reminder of a São Paulo user, owning a recurrence from the factory.
 *
 * @param  array<string, mixed>  $attributes
 */
function scheduledReminder(array $attributes = [], ?string $state = null): Recurrence
{
    $reminder = Reminder::factory()->for(User::factory()->state(['timezone' => 'America/Sao_Paulo']), 'createdBy')->create();
    $factory = Recurrence::factory();

    return ($state === null ? $factory : $factory->{$state}())
        ->for($reminder, 'schedulable')
        ->create(['timezone' => 'America/Sao_Paulo', 'starts_at' => now()->subMonth(), ...$attributes]);
}

test('recurrences are dispatched every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'recurrences:dispatch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('due recurrences dispatch one job per slot', function () {
    $this->seed(LookupSeeder::class);
    $daily = scheduledReminder(['time_of_day' => '09:00', 'next_run_at' => '2026-10-03 12:00:00'], 'daily');
    $once = scheduledReminder(['starts_at' => '2026-10-03 11:00:00', 'next_run_at' => '2026-10-03 11:00:00'], 'once');
    $future = scheduledReminder(['time_of_day' => '09:00', 'next_run_at' => '2026-10-03 12:01:00'], 'daily');

    $this->artisan('recurrences:dispatch')->assertSuccessful();

    Queue::assertPushedTimes(RunScheduledSlot::class, 2);
    Queue::assertPushedOn('default', RunScheduledSlot::class, fn (RunScheduledSlot $job): bool => $job->recurrence->is($daily)
        && $job->slot->toIso8601String() === '2026-10-03T12:00:00+00:00');
    Queue::assertPushedOn('default', RunScheduledSlot::class, fn (RunScheduledSlot $job): bool => $job->recurrence->is($once)
        && $job->slot->toIso8601String() === '2026-10-03T11:00:00+00:00');

    expect($daily->fresh()->last_run_at->toIso8601String())->toBe('2026-10-03T12:00:30+00:00')
        ->and($daily->fresh()->next_run_at->toIso8601String())->toBe('2026-10-04T12:00:00+00:00')
        ->and($once->fresh()->next_run_at)->toBeNull()
        ->and($future->fresh()->last_run_at)->toBeNull()
        ->and($future->fresh()->next_run_at->toIso8601String())->toBe('2026-10-03T12:01:00+00:00');
});

test('the slot runs on the owner queue and calls the owner', function () {
    $this->seed(LookupSeeder::class);
    $recurrence = scheduledReminder(['next_run_at' => '2026-10-03 12:00:00'], 'daily');
    $owner = Mockery::mock(Reminder::class)->makePartial();
    $owner->shouldReceive('runScheduledSlot')->once()->withArgs(fn (CarbonImmutable $slot): bool => $slot->equalTo('2026-10-03 12:00:00'));
    $recurrence->setRelation('schedulable', $owner);

    (new RunScheduledSlot($recurrence, CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC')))->handle();
});

test('missed runs execute only once on recovery', function () {
    $this->seed(LookupSeeder::class);
    // Hourly, last due at 09:00 UTC: the system was down from 09:00 to 12:00.
    $recurrence = scheduledReminder(['next_run_at' => '2026-10-03 09:00:00'], 'cron');
    $recurrence->update(['cron_expression' => '0 * * * *']);

    $this->artisan('recurrences:dispatch')->assertSuccessful();
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    Queue::assertPushedTimes(RunScheduledSlot::class, 1);
    Queue::assertPushed(RunScheduledSlot::class, fn (RunScheduledSlot $job): bool => $job->slot->toIso8601String() === '2026-10-03T09:00:00+00:00');

    expect($recurrence->fresh()->next_run_at->toIso8601String())->toBe('2026-10-03T13:00:00+00:00');
});

test('paused recurrence is not dispatched', function () {
    $this->seed(LookupSeeder::class);
    $recurrence = scheduledReminder(['next_run_at' => '2026-10-03 12:00:00', 'is_paused' => true], 'daily');

    $this->artisan('recurrences:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($recurrence->fresh()->next_run_at->toIso8601String())->toBe('2026-10-03T12:00:00+00:00')
        ->and($recurrence->fresh()->last_run_at)->toBeNull();
});

test('soft deleted owner is not dispatched', function () {
    $this->seed(LookupSeeder::class);
    $recurrence = scheduledReminder(['next_run_at' => '2026-10-03 12:00:00'], 'daily');
    $recurrence->schedulable->delete();

    $this->artisan('recurrences:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($recurrence->fresh()->last_run_at)->toBeNull();
});

test('concurrent dispatch does not duplicate jobs', function () {
    // A second connection plays the other run of the command: its rows are
    // committed, so this test's own (rolled back) transaction sees them.
    config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
    /** @var Connection $other */
    $other = DB::connection('concurrent');

    $removeProbe = function () use ($other): void {
        while ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        $other->table('recurrences')->whereIn('schedulable_id', $other->table('reminders')->where('friendly_id', 'rem_concurrency_probe')->select('id'))->delete();
        $other->table('reminders')->where('friendly_id', 'rem_concurrency_probe')->delete();
        $other->table('users')->where('email', 'concurrency-probe@example.test')->delete();
        $other->table('workspaces')->where('friendly_id', 'wsp_concurrency_probe')->delete();
        $other->table('recurrence_frequencies')->where('slug', RecurrenceFrequency::DAILY)->delete();
        $other->table('locales')->where('slug', 'concurrency-probe')->delete();
    };

    // Runs after RefreshDatabase rolled back, so no row is still locked.
    $this->beforeApplicationDestroyed(function () use ($removeProbe, $other): void {
        $removeProbe();
        $other->disconnect();
    });

    $removeProbe();
    // Should this run ever wait for the other's lock, it fails instead of hanging.
    DB::statement("SET lock_timeout = '2s'");

    $stamps = ['created_at' => now(), 'updated_at' => now()];
    $localeId = $other->table('locales')->insertGetId(['name' => 'Probe', 'slug' => 'concurrency-probe', ...$stamps]);
    $frequencyId = $other->table('recurrence_frequencies')->insertGetId(['name' => 'Daily', 'slug' => RecurrenceFrequency::DAILY, ...$stamps]);
    $userId = $other->table('users')->insertGetId([
        'friendly_id' => 'usr_concurrency_probe', 'name' => 'Probe', 'email' => 'concurrency-probe@example.test',
        'locale_id' => $localeId, 'timezone' => 'America/Sao_Paulo', ...$stamps,
    ]);
    $workspaceId = $other->table('workspaces')->insertGetId(['friendly_id' => 'wsp_concurrency_probe', 'name' => 'Probe', 'color' => 'blue', ...$stamps]);
    $reminderId = $other->table('reminders')->insertGetId([
        'friendly_id' => 'rem_concurrency_probe', 'workspace_id' => $workspaceId, 'title' => 'Probe', 'created_by_id' => $userId, ...$stamps,
    ]);
    $recurrenceId = $other->table('recurrences')->insertGetId([
        'schedulable_type' => 'reminder', 'schedulable_id' => $reminderId, 'recurrence_frequency_id' => $frequencyId,
        'time_of_day' => '09:00:00', 'timezone' => 'America/Sao_Paulo', 'starts_at' => '2026-09-01 00:00:00',
        'next_run_at' => '2026-10-03 12:00:00', 'is_paused' => false, ...$stamps,
    ]);

    // The other run holds the row: this run skips it instead of waiting.
    $other->beginTransaction();
    $other->table('recurrences')->where('id', $recurrenceId)->lockForUpdate()->first();

    $this->artisan('recurrences:dispatch')->assertSuccessful();
    Queue::assertNothingPushed();

    // The other run advances it and commits: it is no longer due here.
    $other->table('recurrences')->where('id', $recurrenceId)->update(['next_run_at' => '2026-10-04 12:00:00', 'last_run_at' => now()]);
    $other->commit();

    $this->artisan('recurrences:dispatch')->assertSuccessful();
    Queue::assertNothingPushed();
});

test('running twice in a row dispatches each slot once', function () {
    $this->seed(LookupSeeder::class);
    scheduledReminder(['next_run_at' => '2026-10-03 12:00:00'], 'daily');

    $this->artisan('recurrences:dispatch')->assertSuccessful();
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    Queue::assertPushedTimes(RunScheduledSlot::class, 1);
});
