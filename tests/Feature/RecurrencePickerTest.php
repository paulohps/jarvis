<?php

use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Weekday;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;
use Tests\Fixtures\RecurrencePickerHost;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:30', 'UTC'));
    $this->user = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $this->reminder = Reminder::factory()->for($this->user, 'createdBy')->create(['title' => 'Water the plants']);
    $this->actingAs($this->user);
});

test('preview shows the next three runs in the user timezone', function () {
    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder])
        ->set('recurrence.frequency', RecurrenceFrequency::DAILY)
        ->set('recurrence.time_of_day', '09:00')
        ->assertSeeHtml('datetime="2026-10-04T09:00:00-03:00"')
        ->assertSeeHtml('datetime="2026-10-05T09:00:00-03:00"')
        ->assertSeeHtml('datetime="2026-10-06T09:00:00-03:00"')
        ->assertDontSeeHtml('datetime="2026-10-07T09:00:00-03:00"')
        ->assertSee('10/04/2026 09:00')
        ->assertSee('Times in America/Sao_Paulo.');
});

test('cron preview shows the next runs and nothing for an invalid expression', function () {
    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder])
        ->set('recurrence.frequency', RecurrenceFrequency::CUSTOM_CRON)
        ->set('recurrence.cron_expression', '0 9 * * 1-5')
        ->assertSeeHtml('data-recurrence-cron')
        // Saturday: Monday, Tuesday and Wednesday at 09:00 local.
        ->assertSeeHtml('datetime="2026-10-05T09:00:00-03:00"')
        ->assertSeeHtml('datetime="2026-10-06T09:00:00-03:00"')
        ->assertSeeHtml('datetime="2026-10-07T09:00:00-03:00"')
        ->set('recurrence.cron_expression', 'every day')
        ->assertDontSeeHtml('data-recurrence-preview-run')
        ->assertSee('No upcoming run.');
});

test('once preset shows only date and time', function () {
    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder])
        ->set('recurrence.frequency', RecurrenceFrequency::ONCE)
        ->set('recurrence.starts_at', '2026-10-10T09:00')
        ->assertSeeHtml('data-recurrence-once')
        ->assertDontSeeHtml('wire:model.live="recurrence.time_of_day"')
        ->assertDontSeeHtml('wire:model.live="recurrence.ends_at"')
        ->assertDontSeeHtml('data-recurrence-weekdays')
        ->assertDontSeeHtml('data-recurrence-monthly')
        ->assertDontSeeHtml('data-recurrence-cron')
        ->assertSeeHtml('datetime="2026-10-10T09:00:00-03:00"');
});

test('each preset shows its own fields', function () {
    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder])
        ->set('recurrence.frequency', RecurrenceFrequency::WEEKLY)
        ->assertSeeHtml('data-recurrence-weekdays')
        ->assertSeeHtml('wire:model.live="recurrence.time_of_day"')
        ->assertDontSeeHtml('data-recurrence-monthly')
        ->set('recurrence.frequency', RecurrenceFrequency::MONTHLY)
        ->assertSeeHtml('data-recurrence-monthly')
        ->assertSeeHtml('wire:model.live="recurrence.day_of_month"')
        ->set('recurrence.is_last_day_of_month', true)
        ->assertDontSeeHtml('wire:model.live="recurrence.day_of_month"')
        ->assertSeeHtml('datetime="2026-10-31T09:00:00-03:00"');
});

test('picker works embedded in another component form', function () {
    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder])
        ->set('title', 'Take out the trash')
        ->set('recurrence.frequency', RecurrenceFrequency::WEEKLY)
        ->set('recurrence.time_of_day', '18:30')
        ->set('recurrence.weekdays', [Weekday::MONDAY, Weekday::THURSDAY])
        ->call('save')
        ->assertHasNoErrors();

    $recurrence = $this->reminder->fresh()->recurrence;

    expect($this->reminder->fresh()->title)->toBe('Take out the trash')
        ->and($recurrence->frequency->slug)->toBe(RecurrenceFrequency::WEEKLY)
        ->and($recurrence->weekdays->pluck('slug')->all())->toEqualCanonicalizing([Weekday::MONDAY, Weekday::THURSDAY])
        ->and($recurrence->timezone)->toBe('America/Sao_Paulo')
        ->and($recurrence->next_run_at->toIso8601String())->toBe('2026-10-05T21:30:00+00:00');
});

test('picker errors show under the embedding property', function () {
    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder])
        ->set('recurrence.frequency', RecurrenceFrequency::WEEKLY)
        ->set('recurrence.weekdays', [])
        ->call('save')
        ->assertHasErrors(['recurrence.weekdays'])
        ->set('recurrence.frequency', RecurrenceFrequency::CUSTOM_CRON)
        ->set('recurrence.cron_expression', '61 * * * *')
        ->call('save')
        ->assertHasErrors(['recurrence.cron_expression'])
        ->assertHasNoErrors(['recurrence.weekdays']);

    expect(Recurrence::query()->count())->toBe(0);
});

test('picker loads the saved recurrence', function () {
    Recurrence::factory()->monthly(31)->for($this->reminder, 'schedulable')->create([
        'time_of_day' => '08:15',
        'timezone' => 'America/Sao_Paulo',
        'starts_at' => '2026-09-01 03:00:00',
        'ends_at' => '2027-01-01 03:00:00',
    ]);

    Livewire::test(RecurrencePickerHost::class, ['reminder' => $this->reminder->fresh()])
        ->assertSet('recurrence.frequency', RecurrenceFrequency::MONTHLY)
        ->assertSet('recurrence.day_of_month', 31)
        ->assertSet('recurrence.time_of_day', '08:15')
        ->assertSet('recurrence.starts_at', '2026-09-01T00:00')
        ->assertSet('recurrence.ends_at', '2027-01-01T00:00')
        ->assertSet('recurrence.timezone', 'America/Sao_Paulo')
        ->assertSeeHtml('datetime="2026-10-31T08:15:00-03:00"')
        ->assertSeeHtml('datetime="2026-11-30T08:15:00-03:00"')
        ->assertSeeHtml('datetime="2026-12-31T08:15:00-03:00"');
});
