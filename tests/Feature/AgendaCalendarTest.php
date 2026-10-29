<?php

use App\Actions\Reminders\CreateReminder;
use App\Livewire\Agenda\AgendaPage;
use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderKind;
use App\Models\User;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Reminders\ReminderOccurrenceFeed;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    // 09:00 on Monday in São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()
        ->withModules([Module::REMINDERS, Module::INVOICES, Module::WHATSAPP])
        ->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
});

/**
 * The calendar of October 2026 in São Paulo, `source|title|start (UTC)`.
 *
 * @param  list<string>  $hidden
 * @return list<string>
 */
function octoberCalendar(User $reader, array $hidden = []): array
{
    $entries = app(CalendarFeed::class)->between(
        $reader,
        CarbonImmutable::parse('2026-10-01', 'America/Sao_Paulo')->utc(),
        CarbonImmutable::parse('2026-11-02', 'America/Sao_Paulo')->utc(),
        hidden: $hidden,
    );

    return array_map(fn (CalendarEntry $entry): string => "{$entry->source}|{$entry->title}|{$entry->startsAt->format('Y-m-d H:i')}", $entries);
}

test('the calendar gathers every source the reader may see', function () {
    app(CreateReminder::class)($this->user, [
        'kind' => 'event', 'title' => 'Dentist', 'due_at' => '2026-10-07T14:00', 'duration_minutes' => 90,
    ]);
    app(CreateReminder::class)($this->user, ['title' => 'Pay the school', 'due_at' => '2026-10-06T10:00']);

    Invoice::factory()->oneOff()->sent()->create(['number' => '202610-001', 'payer_name' => 'Acme', 'sent_at' => '2026-10-02 15:00:00']);
    Invoice::factory()->oneOff()->create(['number' => '202610-002', 'payer_name' => 'Globex', 'issue_date' => '2026-10-20']);
    Invoice::factory()->oneOff()->cancelled()->create(['number' => '202610-003', 'issue_date' => '2026-10-21']);
    // Monthly on the 1st at 09:00.
    InvoiceRecurrence::factory()->create(['name' => 'Rent']);

    $message = WhatsappMessage::factory()->for($this->user)->create(['body' => 'Good morning!']);
    $message->recurrence->update(['starts_at' => '2026-10-09 15:00:00', 'next_run_at' => '2026-10-09 15:00:00']);
    $ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $message->whatsapp_account_id, 'name' => 'Ana']);
    $family = WhatsappTarget::factory()->create(['whatsapp_account_id' => $message->whatsapp_account_id, 'name' => 'Family']);
    $message->targets()->attach([$ana->id, $family->id]);

    $sentMessage = WhatsappMessage::factory()->for($this->user)->create();
    $sentMessage->recurrence->update(['next_run_at' => null]);
    $sentMessage->targets()->attach($ana->id);
    WhatsappDelivery::factory()->scheduled($sentMessage, CarbonImmutable::parse('2026-10-03 11:00', 'UTC'))->sent()->create();

    // Someone else's message stays theirs.
    WhatsappMessage::factory()->withTargets()->create();

    // The past run of the invoice schedule shows as the invoice it made.
    expect(octoberCalendar($this->user))->toBe([
        'invoices|Invoice 202610-001 · Acme|2026-10-02 15:00',
        'whatsapp|WhatsApp to Ana|2026-10-03 11:00',
        'reminders|Pay the school|2026-10-06 13:00',
        'events|Dentist|2026-10-07 17:00',
        'whatsapp|WhatsApp to Ana, Family|2026-10-09 15:00',
        // An invoice not sent yet is all day on its issue date.
        'invoices|Invoice 202610-002 · Globex|2026-10-20 15:00',
        'invoices|Invoice: Rent|2026-11-01 12:00',
    ]);

    expect(octoberCalendar($this->user, hidden: [CalendarFeed::INVOICES, CalendarFeed::WHATSAPP]))->toBe([
        'reminders|Pay the school|2026-10-06 13:00',
        'events|Dentist|2026-10-07 17:00',
    ]);
});

test('a source is only on the calendar of who has its module', function () {
    Invoice::factory()->oneOff()->sent()->create(['sent_at' => '2026-10-02 15:00:00']);
    $member = User::factory()->withModules([Module::REMINDERS])->create();

    expect(app(CalendarFeed::class)->keysFor($member))->toBe([CalendarFeed::REMINDERS, CalendarFeed::EVENTS])
        ->and(octoberCalendar($member))->toBe([]);
});

test('a repeating reminder shows its coming runs, and only mine narrows them', function () {
    $member = User::factory()->withModules([Module::REMINDERS])->create();
    app(CreateReminder::class)($this->user, ['title' => 'Feed the cat', 'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '18:00']]);
    app(CreateReminder::class)($this->user, ['title' => 'Ana\'s errand', 'due_at' => '2026-10-06T10:00', 'assigned_to' => $member->friendly_id]);

    $week = fn (?User $assignee): array => array_map(
        fn (CalendarEntry $entry): string => $entry->title.($entry->isProjected ? ' (projected)' : ''),
        app(CalendarFeed::class)->between($this->user, CarbonImmutable::parse('2026-10-05 03:00', 'UTC'), CarbonImmutable::parse('2026-10-08 03:00', 'UTC'), $assignee),
    );

    expect($week(null))->toBe(['Feed the cat', 'Ana\'s errand', 'Feed the cat (projected)', 'Feed the cat (projected)'])
        ->and($week($this->user))->toBe(['Feed the cat', 'Feed the cat (projected)', 'Feed the cat (projected)']);
});

test('the month grid shows the items of each day and hides a source by its chip', function () {
    app(CreateReminder::class)($this->user, ['kind' => 'event', 'title' => 'Dentist', 'due_at' => '2026-10-07T14:00', 'duration_minutes' => 60]);
    Invoice::factory()->oneOff()->sent()->create(['number' => '202610-001', 'payer_name' => 'Acme', 'sent_at' => '2026-10-02 15:00:00']);

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class)
        ->assertSet('view', 'month')
        ->assertSee('October 2026')
        // Sunday, September 27 to Saturday, November 7.
        ->assertSeeHtml('data-agenda-day="2026-09-27"')
        ->assertSeeHtml('data-agenda-day="2026-11-07"')
        ->assertSeeInOrder(['Invoice 202610-001 · Acme', 'Dentist'])
        ->call('toggleSource', CalendarFeed::INVOICES)
        ->assertSet('hide', [CalendarFeed::INVOICES])
        ->assertDontSee('Invoice 202610-001 · Acme')
        ->assertSee('Dentist')
        ->call('next')
        ->assertSet('date', '2026-11-01')
        ->assertSee('November 2026')
        ->call('today')
        ->call('openDay', '2026-10-07')
        ->assertSet('view', 'day')
        ->assertSee('Wednesday, October 7, 2026')
        ->assertSeeHtml('data-agenda-timeline="day"')
        ->assertSee('14:00 – 15:00');
});

test('the week runs from sunday and moves a week at a time', function () {
    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'week', 'date' => '2026-10-07'])
        ->assertSeeHtml('data-agenda-day="2026-10-04"')
        ->assertSeeHtml('data-agenda-day="2026-10-10"')
        ->assertDontSeeHtml('data-agenda-day="2026-10-11"')
        ->call('previous')
        ->assertSet('date', '2026-09-30')
        ->assertSee('Sep – Oct 2026');
});

test('an invalid view, date or source in the address falls back', function () {
    Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'year', 'date' => '2026-02-30', 'hide' => ['invoices', 'nope']])
        ->assertSet('view', 'month')
        ->assertSet('date', '')
        ->assertSet('hide', ['invoices']);
});

test('a click on an empty hour opens the form there, and an event is saved with its duration', function () {
    $component = Livewire::actingAs($this->user)
        ->test(AgendaPage::class, ['view' => 'week'])
        ->call('createAt', '2026-10-08', 15)
        ->assertSet('showForm', true)
        ->assertSet('due_at', '2026-10-08T15:00')
        ->set('kind', 'event')
        ->set('title', 'Parents meeting')
        ->set('duration_minutes', 45)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Event “Parents meeting” saved.');

    $event = Reminder::query()->where('title', 'Parents meeting')->sole();

    expect($event->kind)->toBe(ReminderKind::Event)
        ->and($event->duration_minutes)->toBe(45)
        ->and($event->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');

    // An event is not a task.
    expect(app(ReminderOccurrenceFeed::class)->counts($this->user))->toBe(['pending' => 0, 'overdue' => 0, 'completed' => 0]);

    $component->call('edit', $event->friendly_id)
        ->assertSet('kind', 'event')
        ->assertSet('duration_minutes', 45)
        ->assertSet('due_at', '2026-10-08T15:00');
});

test('an all-day item is due at nine on its date, and an event needs a date', function () {
    Livewire::actingAs($this->user)
        ->test(AgendaPage::class)
        ->call('createAt', '2026-10-09')
        ->set('title', 'Market day')
        ->set('is_all_day', true)
        ->call('save')
        ->assertHasNoErrors();

    $reminder = Reminder::query()->where('title', 'Market day')->sole();

    expect($reminder->is_all_day)->toBeTrue()
        ->and($reminder->occurrences()->sole()->due_at->toIso8601String())->toBe('2026-10-09T12:00:00+00:00');

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class)
        ->call('create')
        ->set('kind', 'event')
        ->assertSet('hasDate', true)
        ->set('title', 'Trip')
        ->set('due_at', '')
        ->call('save')
        ->assertHasErrors('due_at');
});

test('the api gives the calendar of a period', function () {
    $token = $this->user->createToken('iPhone')->plainTextToken;
    app(CreateReminder::class)($this->user, ['kind' => 'event', 'title' => 'Dentist', 'due_at' => '2026-10-07T14:00', 'duration_minutes' => 90]);
    $task = app(CreateReminder::class)($this->user, ['title' => 'Pay the school', 'due_at' => '2026-10-06T10:00']);
    Invoice::factory()->oneOff()->sent()->create(['number' => '202610-001', 'payer_name' => 'Acme', 'sent_at' => '2026-10-02 15:00:00']);

    $this->withToken($token)
        ->getJson('/api/v1/agenda?from=2026-10-01&to=2026-10-08&hide[]=invoices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.source', 'reminders')
        ->assertJsonPath('data.0.title', 'Pay the school')
        ->assertJsonPath('data.0.starts_at', '2026-10-06T13:00:00+00:00')
        ->assertJsonPath('data.0.is_completable', true)
        ->assertJsonPath('data.0.reminder_id', $task->friendly_id)
        ->assertJsonPath('data.0.occurrence_id', $task->occurrences()->sole()->friendly_id)
        ->assertJsonPath('data.1.source', 'events')
        ->assertJsonPath('data.1.ends_at', '2026-10-07T18:30:00+00:00')
        ->assertJsonPath('data.1.is_completable', false)
        ->assertJsonPath('meta.from', '2026-10-01T03:00:00+00:00')
        ->assertJsonPath('meta.sources', ['reminders', 'events', 'invoices', 'whatsapp'])
        ->assertJsonPath('meta.assignees.0.friendly_id', $this->user->friendly_id);

    $this->withToken($token)
        ->getJson('/api/v1/agenda?from=2026-10-01&to=2026-12-31')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');

    $this->withToken($token)
        ->getJson('/api/v1/agenda?from=2026-10-01&to=2026-10-08&hide[]=nope')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hide.0');
});

test('the api agenda needs the module', function () {
    $outsider = User::factory()->create();

    $this->withToken($outsider->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/agenda?from=2026-10-01&to=2026-10-08')
        ->assertForbidden();
});
