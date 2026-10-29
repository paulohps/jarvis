<?php

use App\Actions\Invoices\ApproveInvoice;
use App\Actions\Invoices\CreateIssuer;
use App\Actions\Invoices\CreateOneOffInvoice;
use App\Actions\Invoices\CreatePayer;
use App\Actions\Invoices\DeletePayer;
use App\Actions\Invoices\GenerateAndSendNow;
use App\Actions\Invoices\SaveInvoiceRecurrence;
use App\Actions\Invoices\UpdatePayer;
use App\Livewire\Invoices\InvoiceHistory;
use App\Livewire\Invoices\InvoiceRecurrenceForm;
use App\Livewire\Invoices\InvoiceRecurrenceList;
use App\Livewire\Invoices\IssuerList;
use App\Livewire\Invoices\PayerList;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\Issuer;
use App\Models\Module;
use App\Models\Payer;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Invoices\InvoiceNotifier;
use App\Services\Invoices\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake(InvoicePdf::disk());
    Pdf::fake();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

    $this->household = WorkspaceFactory::household();
    $this->smiths = Workspace::factory()->create(['name' => 'Smith family', 'color' => 'green']);

    // Invoices in the household only; and in both, the household by default.
    $this->user = User::factory()->withModules([Module::INVOICES])->create();
    $this->both = User::factory()->withModules([Module::INVOICES])->inWorkspace($this->smiths, [Module::INVOICES])->create();

    $this->recurrenceInput = fn (Payer $payer, Issuer $issuer, array $overrides = []): array => [
        'name' => 'Monthly',
        'payer' => $payer->friendly_id,
        'issuer' => $issuer->friendly_id,
        'recipients' => ['ap@example.com'],
        'email_subject' => 'Invoice {% invoice.number %}',
        'email_body' => 'Please find the invoice attached.',
        'service_description' => 'Consulting.',
        'currency' => Currency::USD,
        'amount' => '100.00',
        'invoice_date_rule' => InvoiceDateRule::EXECUTION_DAY,
        'invoice_due_date_rule' => InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH,
        'delivery_mode' => InvoiceDeliveryMode::REQUIRES_REVIEW,
        'recurrence' => ['frequency' => RecurrenceFrequency::MONTHLY, 'day_of_month' => 1, 'time_of_day' => '08:00'],
        ...$overrides,
    ];

    $this->errorsOf = fn (Closure $callback): array => rescue(
        fn () => $callback() && [],
        fn (ValidationException $exception): array => $exception->errors(),
        report: false,
    );
});

test('the same number in two workspaces keeps two pdfs', function () {
    $ours = Invoice::factory()->create(['workspace_id' => $this->household->id, 'number' => '202610-001']);
    $theirs = Invoice::factory()->create(['workspace_id' => $this->smiths->id, 'number' => '202610-001']);

    $ourPath = InvoicePdf::store($ours);
    $theirPath = InvoicePdf::store($theirs);

    expect($ourPath)->toBe("invoices/{$this->household->friendly_id}/202610-001.pdf")
        ->and($theirPath)->toBe("invoices/{$this->smiths->friendly_id}/202610-001.pdf");
    Storage::disk(InvoicePdf::disk())->assertExists([$ourPath, $theirPath]);
});

test('another workspace invoices are neither listed nor opened', function () {
    $payer = Payer::factory()->create(['workspace_id' => $this->smiths->id, 'name' => 'Smith payer']);
    $issuer = Issuer::factory()->create(['workspace_id' => $this->smiths->id, 'name' => 'Smith issuer']);
    $recurrence = InvoiceRecurrence::factory()->create(['workspace_id' => $this->smiths->id, 'name' => 'Smith recurrence']);
    $invoice = Invoice::factory()->for($recurrence, 'recurrence')->sent()->create();
    Storage::disk(InvoicePdf::disk())->put($invoice->pdf_path, '%PDF');

    expect($recurrence->payer->workspace_id)->toBe($this->smiths->id)
        ->and($invoice->workspace_id)->toBe($this->smiths->id);

    Livewire::actingAs($this->user)->test(PayerList::class)->assertDontSee('Smith payer');
    Livewire::actingAs($this->user)->test(IssuerList::class)->assertDontSee('Smith issuer');
    Livewire::actingAs($this->user)->test(InvoiceRecurrenceList::class)->assertDontSee('Smith recurrence');
    Livewire::actingAs($this->user)->test(InvoiceHistory::class)->assertDontSee($invoice->number);

    $this->actingAs($this->user)->get(route('invoices.recurrences.edit', $recurrence))->assertNotFound();
    $this->actingAs($this->user)->get(route('invoices.recurrences.preview-pdf', $recurrence))->assertNotFound();
    $this->actingAs($this->user)->get(route('invoices.history.show', $invoice))->assertNotFound();
    $this->actingAs($this->user)->get(route('invoices.history.pdf', $invoice))->assertNotFound();

    Livewire::actingAs($this->user)->test(PayerList::class)->call('edit', $payer->friendly_id)->assertNotFound();

    expect(fn () => app(UpdatePayer::class)($this->user, $payer, ['name' => 'Taken', 'address' => 'Here']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeletePayer::class)($this->user, $payer))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(GenerateAndSendNow::class)($this->user, $recurrence))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ApproveInvoice::class)($this->user, $invoice))->toThrow(ModelNotFoundException::class)
        ->and($payer->fresh()->name)->toBe('Smith payer')
        ->and($recurrence->invoices()->count())->toBe(1);

    // A member of both sees it, marked with its workspace.
    Livewire::actingAs($this->both)->test(PayerList::class)
        ->assertSee('Smith payer')
        ->assertSee('data-invoice-workspace="'.$this->smiths->friendly_id.'"', false);
    Livewire::actingAs($this->both)->test(InvoiceHistory::class)
        ->assertSee($invoice->number)
        ->assertSee('data-invoice-workspace="'.$this->smiths->friendly_id.'"', false);
    $this->actingAs($this->both)->get(route('invoices.recurrences.edit', $recurrence))->assertOk();
    $this->actingAs($this->both)->get(route('invoices.history.pdf', $invoice))->assertOk();

    // A user in a single workspace sees no workspace at all.
    Payer::factory()->create(['name' => 'Household payer']);
    Livewire::actingAs($this->user)->test(PayerList::class)
        ->assertSee('Household payer')
        ->assertDontSee('data-invoice-workspace', false);
});

test('new payers and issuers go to the default or the chosen workspace', function () {
    $input = ['name' => 'Acme', 'address' => '1 Main St'];

    expect(app(CreatePayer::class)($this->both, $input)->workspace_id)->toBe($this->household->id)
        ->and(app(CreatePayer::class)($this->both, [...$input, 'workspace' => $this->smiths->friendly_id])->workspace_id)->toBe($this->smiths->id)
        ->and(($this->errorsOf)(fn () => app(CreatePayer::class)($this->user, [...$input, 'workspace' => $this->smiths->friendly_id])))->toHaveKey('workspace')
        ->and(($this->errorsOf)(fn () => app(CreatePayer::class)($this->both, [...$input, 'workspace' => 'wsp_unknown'])))->toHaveKey('workspace');

    $issuer = app(CreateIssuer::class)($this->both, [
        'name' => 'Paulo', 'email' => 'paulo@example.com', 'postal_code' => '01000-000', 'street' => 'Rua A, 1',
        'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'workspace' => $this->smiths->friendly_id,
    ]);

    expect($issuer->workspace_id)->toBe($this->smiths->id);

    // The sheet offers the workspace only to a user with invoices in several.
    Livewire::actingAs($this->user)->test(PayerList::class)
        ->call('create')
        ->assertDontSee('data-payer-workspace', false);

    Livewire::actingAs($this->both)->test(PayerList::class)
        ->call('create')
        ->assertSet('workspace', $this->household->friendly_id)
        ->assertSee('data-payer-workspace', false)
        ->set('workspace', $this->smiths->friendly_id)
        ->set('name', 'Sheet payer')
        ->set('address', '2 Main St')
        ->call('save')
        ->assertHasNoErrors();

    expect(Payer::query()->where('name', 'Sheet payer')->sole()->workspace_id)->toBe($this->smiths->id);
});

test('recurrences and one off invoices bill payers and issuers of their workspace', function () {
    $householdPayer = Payer::factory()->create(['name' => 'Household payer']);
    $householdIssuer = Issuer::factory()->create();
    $smithPayer = Payer::factory()->create(['workspace_id' => $this->smiths->id, 'name' => 'Smith payer']);
    $smithIssuer = Issuer::factory()->create(['workspace_id' => $this->smiths->id]);
    $save = fn (array $input, ?InvoiceRecurrence $existing = null): InvoiceRecurrence => app(SaveInvoiceRecurrence::class)($this->both, $input, $existing);

    $errors = ($this->errorsOf)(fn () => $save(($this->recurrenceInput)($householdPayer, $householdIssuer, ['workspace' => $this->smiths->friendly_id])));

    expect($errors)->toHaveKeys(['payer', 'issuer'])
        ->and(InvoiceRecurrence::query()->count())->toBe(0);

    $recurrence = $save(($this->recurrenceInput)($smithPayer, $smithIssuer, ['workspace' => $this->smiths->friendly_id]));

    expect($recurrence->workspace_id)->toBe($this->smiths->id);

    // The workspace is fixed: editing ignores it and still asks for its own payer.
    $save(($this->recurrenceInput)($smithPayer, $smithIssuer, ['name' => 'Renamed', 'workspace' => $this->household->friendly_id]), $recurrence);

    expect($recurrence->fresh()->workspace_id)->toBe($this->smiths->id)
        ->and($recurrence->fresh()->name)->toBe('Renamed')
        ->and(($this->errorsOf)(fn () => $save(($this->recurrenceInput)($householdPayer, $smithIssuer), $recurrence)))->toHaveKey('payer');

    // The form lists the payers of the workspace picked, the default first.
    Livewire::actingAs($this->both)->test(InvoiceRecurrenceForm::class)
        ->assertSet('workspace', $this->household->friendly_id)
        ->assertSee('data-recurrence-workspace', false)
        ->assertSee('Household payer')
        ->assertDontSee('Smith payer')
        ->set('payer', $householdPayer->friendly_id)
        ->set('workspace', $this->smiths->friendly_id)
        ->assertSet('payer', '')
        ->assertSee('Smith payer')
        ->assertDontSee('Household payer');

    Livewire::actingAs($this->user)->test(InvoiceRecurrenceForm::class)->assertDontSee('data-recurrence-workspace', false);

    // A one-off invoice follows the same rules.
    $oneOff = fn (Payer $payer, Issuer $issuer, array $overrides = []): Invoice => app(CreateOneOffInvoice::class)($this->both, [
        ...collect(($this->recurrenceInput)($payer, $issuer))->except(['name', 'recurrence'])->all(),
        ...$overrides,
    ]);

    expect(($this->errorsOf)(fn () => $oneOff($householdPayer, $householdIssuer, ['workspace' => $this->smiths->friendly_id])))->toHaveKeys(['payer', 'issuer'])
        ->and($oneOff($smithPayer, $smithIssuer, ['workspace' => $this->smiths->friendly_id])->workspace_id)->toBe($this->smiths->id)
        ->and($oneOff($householdPayer, $householdIssuer)->workspace_id)->toBe($this->household->id);
});

test('invoices are numbered per workspace and inherit the recurrence workspace', function () {
    Invoice::factory()->oneOff()->count(2)->create();
    $recurrence = InvoiceRecurrence::factory()->requiresReview()->withRecipients(1)->create(['workspace_id' => $this->smiths->id]);

    $recurrence->runScheduledSlot(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

    $invoice = $recurrence->invoices()->sole();

    expect($invoice->workspace_id)->toBe($this->smiths->id)
        ->and($invoice->number)->toBe('202610-001');

    $householdInvoice = app(GenerateAndSendNow::class)($this->both, InvoiceRecurrence::factory()->withRecipients(1)->create());

    expect($householdInvoice->workspace_id)->toBe($this->household->id)
        ->and($householdInvoice->number)->toBe('202610-003');
});

test('invoice notifications go only to the members of its workspace', function () {
    $smith = User::factory()->inWorkspace($this->smiths, [Module::INVOICES])->create();
    $smithWithoutInvoices = User::factory()->inWorkspace($this->smiths, [Module::SHOPPING])->create();
    $smithDeactivated = User::factory()->inWorkspace($this->smiths, [Module::INVOICES])->deactivated()->create();
    $outsideAdmin = User::factory()->superAdmin()->create();

    $invoice = Invoice::factory()->oneOff()->pendingReview()->create(['workspace_id' => $this->smiths->id]);

    expect(InvoiceNotifier::audience($invoice)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->both->id, $smith->id])->sort()->values()->all())
        ->and(InvoiceNotifier::audience($invoice)->pluck('id'))->not->toContain($this->user->id, $smithWithoutInvoices->id, $smithDeactivated->id, $outsideAdmin->id);
});
