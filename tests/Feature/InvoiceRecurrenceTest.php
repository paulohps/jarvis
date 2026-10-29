<?php

use App\Actions\Invoices\DeleteInvoiceRecurrence;
use App\Actions\Invoices\SaveInvoiceRecurrence;
use App\Livewire\Invoices\InvoiceRecurrenceForm;
use App\Livewire\Invoices\InvoiceRecurrenceList;
use App\Models\Currency;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\Issuer;
use App\Models\Locale;
use App\Models\MailService;
use App\Models\Module;
use App\Models\Payer;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'America/Sao_Paulo']);
    $this->payer = Payer::factory()->create(['name' => 'Initech Corp']);
    $this->issuer = Issuer::factory()->create(['name' => 'Jane Doe Consulting Ltda']);

    // The configuration of the project description (Workflow 7).
    $this->input = fn (array $overrides = []): array => [
        'name' => 'Initech monthly',
        'payer' => $this->payer->friendly_id,
        'issuer' => $this->issuer->friendly_id,
        'recipients' => ['ap@initech.example'],
        'email_subject' => 'Invoice {% invoice.number %} — {% current_month_name %} {% current_year %}',
        'email_body' => 'Please find attached the invoice {% invoice.number %}.',
        'service_description' => "Software development services as agreed.\nDocumentation of the delivered systems.",
        'currency' => Currency::USD,
        'amount' => '2000.00',
        'invoice_date_rule' => InvoiceDateRule::EXECUTION_DAY,
        'invoice_date_day' => null,
        'invoice_due_date_rule' => InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH,
        'due_date_value' => null,
        'delivery_mode' => InvoiceDeliveryMode::AUTOMATIC,
        'recurrence' => ['frequency' => RecurrenceFrequency::MONTHLY, 'day_of_month' => 1, 'time_of_day' => '08:00'],
        ...$overrides,
    ];

    $this->save = fn (array $overrides = [], ?InvoiceRecurrence $existing = null): InvoiceRecurrence => app(SaveInvoiceRecurrence::class)($this->user, ($this->input)($overrides), $existing);

    $this->errorsOf = fn (array $overrides): array => rescue(
        fn () => ($this->save)($overrides) && [],
        function (ValidationException $exception): array {
            expect($exception->status)->toBe(422);

            return $exception->errors();
        },
        report: false,
    );
});

test('recurrence requires between one and ten recipients', function () {
    $emails = fn (int $count): array => array_map(fn (int $index): string => "billing{$index}@example.com", range(1, $count));

    expect(($this->errorsOf)(['recipients' => []]))->toHaveKey('recipients')
        ->and(($this->errorsOf)(['recipients' => $emails(11)]))->toHaveKey('recipients')
        ->and(($this->errorsOf)(['recipients' => ['a@example.com', 'not-an-email']]))->toHaveKey('recipients.1')
        ->and(($this->errorsOf)(['recipients' => ['a@example.com', ' A@Example.com ']]))->toHaveKeys(['recipients.0', 'recipients.1']);

    expect(InvoiceRecurrence::query()->count())->toBe(0);

    $ten = ($this->save)(['recipients' => $emails(10)]);

    expect($ten->recipients()->count())->toBe(10);

    // Editing keeps, adds and drops addresses.
    ($this->save)(['recipients' => ['billing1@example.com', 'new@example.com']], $ten);

    expect($ten->recipients()->orderBy('email')->pluck('email')->all())->toBe(['billing1@example.com', 'new@example.com']);
});

test('recurrence rejects unknown template variables', function () {
    $errors = ($this->errorsOf)(['email_subject' => 'Invoice {% invoice.total %}', 'email_body' => '{% if invoice.number %}Hi']);

    expect($errors)->toHaveKeys(['email_subject', 'email_body'])
        ->and($errors['email_subject'][0])->toContain('invoice.total');

    expect(InvoiceRecurrence::query()->count())->toBe(0);

    // The same error reaches the screen's editor.
    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class)
        ->set('email_subject', 'Invoice {% invoice.total %}')
        ->call('save')
        ->assertHasErrors('email_subject')
        ->assertSee('invoice.total');
});

test('amount must be positive', function () {
    foreach (['0', '-10', 'abc', '10.123', '', '10000000000'] as $amount) {
        expect(($this->errorsOf)(['amount' => $amount]))->toHaveKey('amount');
    }

    // The currency must be an active one too.
    Currency::query()->where('slug', Currency::EUR)->update(['is_active' => false]);

    expect(($this->errorsOf)(['currency' => Currency::EUR]))->toHaveKey('currency')
        ->and(($this->errorsOf)(['currency' => 'XYZ']))->toHaveKey('currency');

    expect(($this->save)(['amount' => '2000'])->fresh()->amount)->toBe('2000.00')
        ->and(($this->save)(['amount' => '0.01'])->fresh()->amount)->toBe('0.01');
});

test('due date value must match rule', function () {
    expect(($this->errorsOf)(['invoice_due_date_rule' => InvoiceDueDateRule::DAYS_AFTER_ISSUE, 'due_date_value' => null]))->toHaveKey('due_date_value')
        ->and(($this->errorsOf)(['invoice_due_date_rule' => InvoiceDueDateRule::DAYS_AFTER_ISSUE, 'due_date_value' => 366]))->toHaveKey('due_date_value')
        ->and(($this->errorsOf)(['invoice_due_date_rule' => InvoiceDueDateRule::DAYS_AFTER_ISSUE, 'due_date_value' => -1]))->toHaveKey('due_date_value')
        ->and(($this->errorsOf)(['invoice_due_date_rule' => InvoiceDueDateRule::DAY_OF_NEXT_MONTH, 'due_date_value' => 0]))->toHaveKey('due_date_value')
        ->and(($this->errorsOf)(['invoice_due_date_rule' => InvoiceDueDateRule::DAY_OF_NEXT_MONTH, 'due_date_value' => 40]))->toHaveKey('due_date_value')
        ->and(($this->errorsOf)(['invoice_due_date_rule' => 'whenever']))->toHaveKey('invoice_due_date_rule')
        ->and(($this->errorsOf)(['invoice_date_rule' => InvoiceDateRule::DAY_OF_CURRENT_MONTH, 'invoice_date_day' => 32]))->toHaveKey('invoice_date_day')
        ->and(($this->errorsOf)(['invoice_date_rule' => InvoiceDateRule::DAY_OF_CURRENT_MONTH, 'invoice_date_day' => null]))->toHaveKey('invoice_date_day');

    // 40 is fine as a number of days, and the value of a rule without one is cleared.
    expect(($this->save)(['invoice_due_date_rule' => InvoiceDueDateRule::DAYS_AFTER_ISSUE, 'due_date_value' => 40])->due_date_value)->toBe(40)
        ->and(($this->save)(['invoice_due_date_rule' => InvoiceDueDateRule::DAY_OF_NEXT_MONTH, 'due_date_value' => '31'])->due_date_value)->toBe(31)
        ->and(($this->save)(['due_date_value' => 12, 'invoice_date_day' => 9])->only(['due_date_value', 'invoice_date_day']))->toBe(['due_date_value' => null, 'invoice_date_day' => null]);

    // Payer, issuer and delivery mode must be valid as well.
    $this->payer->delete();

    expect(($this->errorsOf)([]))->toHaveKey('payer')
        ->and(($this->errorsOf)(['issuer' => 'iss_missing', 'delivery_mode' => 'carrier_pigeon']))->toHaveKeys(['issuer', 'delivery_mode']);
});

test('saving computes next run at', function () {
    $saved = ($this->save)();
    $schedule = $saved->recurrence;

    // Monthly on day 1 at 08:00 São Paulo, saved on October 15th: November 1st, 11:00 UTC.
    expect($schedule)->toBeInstanceOf(Recurrence::class)
        ->and($schedule->schedulable_type)->toBe('invoice_recurrence')
        ->and($schedule->timezone)->toBe('America/Sao_Paulo')
        ->and($schedule->next_run_at->utc()->toDateTimeString())->toBe('2026-11-01 11:00:00')
        ->and($saved->created_by_id)->toBe($this->user->id)
        ->and($saved->amount)->toBe('2000.00')
        ->and($saved->recipients()->pluck('email')->all())->toBe(['ap@initech.example']);

    // A refused schedule saves nothing, and its errors come under `recurrence.`.
    $errors = ($this->errorsOf)(['name' => 'Broken', 'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'time_of_day' => '08:00', 'weekdays' => []]]);

    expect($errors)->toHaveKey('recurrence.weekdays')
        ->and(InvoiceRecurrence::query()->where('name', 'Broken')->exists())->toBeFalse();

    // The screen creates through the same Action and then shows the next run
    // in the reader's timezone.
    $component = Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class)
        ->assertSet('recurrence.frequency', RecurrenceFrequency::MONTHLY)
        ->set('name', 'From the screen')
        ->set('payer', $this->payer->friendly_id)
        ->set('issuer', $this->issuer->friendly_id)
        ->set('recipients.0', 'ap@initech.example')
        ->set('service_description', 'Maintenance')
        ->set('amount', '2000')
        ->call('save')
        ->assertHasNoErrors();

    $created = InvoiceRecurrence::query()->where('name', 'From the screen')->sole();

    $component->assertRedirect(route('invoices.recurrences.edit', $created));

    $tokyo = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'Asia/Tokyo']);

    $this->actingAs($tokyo)
        ->get(route('invoices.recurrences.edit', $created))
        ->assertOk()
        ->assertSee('From the screen')
        ->assertSeeInOrder(['data-recurrence-next-run', '11/01/2026 20:00', 'Asia/Tokyo'], false);

    $this->actingAs($this->user)
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertSee('From the screen')
        ->assertSee('11/01/2026 08:00');

    // Without the module: no screen, no Action.
    $outsider = User::factory()->withModules([Module::SHOPPING])->create();

    $this->actingAs($outsider)->get(route('invoices.recurrences.create'))->assertForbidden();

    expect(fn () => app(SaveInvoiceRecurrence::class)($outsider, ($this->input)()))->toThrow(AuthorizationException::class);
});

test('deleting recurrence pauses its schedule', function () {
    $saved = ($this->save)();

    // Pause and resume from the screen.
    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class, ['invoiceRecurrence' => $saved])
        ->assertSet('name', 'Initech monthly')
        ->assertSet('recipients', ['ap@initech.example'])
        ->call('pause')
        ->assertSee('data-recurrence-state="paused"', false)
        ->call('resume')
        ->assertSee('data-recurrence-state="active"', false);

    expect($saved->recurrence()->value('is_paused'))->toBeFalse();

    app(DeleteInvoiceRecurrence::class)($this->user, $saved);

    $schedule = Recurrence::query()->where('schedulable_type', 'invoice_recurrence')->where('schedulable_id', $saved->id)->sole();

    expect($saved->fresh()->trashed())->toBeTrue()
        ->and($schedule->is_paused)->toBeTrue()
        ->and(Recurrence::query()->due(now()->addYear())->count())->toBe(0);

    $this->actingAs($this->user)->get(route('invoices.recurrences.edit', $saved))->assertNotFound();

    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceList::class)
        ->assertDontSee('Initech monthly');

    // Deleting from the screen goes back to the list.
    $other = ($this->save)(['name' => 'Another one']);

    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class, ['invoiceRecurrence' => $other])
        ->call('delete')
        ->assertRedirect(route('invoices.index'));

    expect($other->fresh()->trashed())->toBeTrue()
        ->and($other->recurrence()->value('is_paused'))->toBeTrue();
});

test('recurrence picks a mail service the user may send through', function () {
    $own = MailService::factory()->for($this->user)->create(['name' => 'My SMTP']);
    $global = MailService::factory()->global()->create(['name' => 'Family Resend']);
    $foreign = MailService::factory()->create(['name' => 'Someone else']);

    expect(($this->errorsOf)(['mail_service' => $foreign->friendly_id]))->toHaveKey('mail_service');

    $recurrence = ($this->save)(['mail_service' => $own->friendly_id]);

    expect($recurrence->mail_service_id)->toBe($own->id);

    ($this->save)(['mail_service' => $global->friendly_id], $recurrence);
    expect($recurrence->refresh()->mail_service_id)->toBe($global->id);

    ($this->save)(['mail_service' => null], $recurrence);
    expect($recurrence->refresh()->mail_service_id)->toBeNull();

    // The screen offers the user's own and the global ones.
    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class, ['invoiceRecurrence' => $recurrence])
        ->assertSet('mail_service', '')
        ->assertSee('My SMTP')
        ->assertSee('Family Resend')
        ->assertDontSee('Someone else');
});

test('recurrence e-mail language is picked apart from the user language', function () {
    $this->user->update(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    expect(($this->errorsOf)(['locale' => 'fr']))->toHaveKey('locale');

    $recurrence = ($this->save)([]);

    expect($recurrence->locale->slug)->toBe(Locale::EN);

    ($this->save)(['locale' => Locale::PT_BR], $recurrence);
    expect($recurrence->refresh()->locale->slug)->toBe(Locale::PT_BR);

    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class, ['invoiceRecurrence' => $recurrence])
        ->assertSet('locale', Locale::PT_BR)
        ->set('locale', Locale::EN)
        ->call('save')
        ->assertHasNoErrors();

    expect($recurrence->refresh()->locale->slug)->toBe(Locale::EN);
});
