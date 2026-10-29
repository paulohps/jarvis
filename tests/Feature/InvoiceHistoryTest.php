<?php

use App\Livewire\Invoices\InvoiceHistory;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Module;
use App\Models\Payer;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake(InvoicePdf::disk());
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::INVOICES])->create();
});

/**
 * A one-off invoice of a payer issued on a day.
 */
function historyInvoice(Payer $payer, string $issueDate, string $state): Invoice
{
    $issue = CarbonImmutable::parse($issueDate);

    return Invoice::factory()->oneOff()->{$state}()->create([
        'payer_id' => $payer->id,
        'number_year_month' => $issue->format('Ym'),
        'issue_date' => $issue->toDateString(),
        'due_date' => $issue->endOfMonth()->toDateString(),
        'currency_id' => Currency::idFor(Currency::USD),
        'amount' => '2000.00',
    ]);
}

test('invoice history filters by status payer and month', function () {
    $initech = Payer::factory()->create(['name' => 'Initech Corp']);
    $acme = Payer::factory()->create(['name' => 'Acme Inc']);

    $initechSeptemberSent = historyInvoice($initech, '2026-09-01', 'sent');
    $initechOctoberSent = historyInvoice($initech, '2026-10-01', 'sent');
    $initechOctoberFailed = historyInvoice($initech, '2026-10-02', 'failed');
    $acmeOctoberSent = historyInvoice($acme, '2026-10-03', 'sent');
    $acmeOctoberPending = historyInvoice($acme, '2026-10-04', 'pendingReview');

    $numbers = fn ($component): array => $component->instance()->invoices->pluck('number')->all();

    $component = Livewire::actingAs($this->user)->test(InvoiceHistory::class);

    // Newest first, with every column.
    expect($numbers($component))->toBe([
        $acmeOctoberPending->number, $acmeOctoberSent->number, $initechOctoberFailed->number,
        $initechOctoberSent->number, $initechSeptemberSent->number,
    ]);
    $component->assertSeeInOrder([
        $acmeOctoberPending->number, 'Acme Inc', '04/10/2026', '31/10/2026', 'USD', '2,000.00', __('Pending review'),
    ]);

    $component->set('status', InvoiceStatus::SENT);
    expect($numbers($component))->toBe([$acmeOctoberSent->number, $initechOctoberSent->number, $initechSeptemberSent->number]);

    $component->set('payer', $initech->friendly_id);
    expect($numbers($component))->toBe([$initechOctoberSent->number, $initechSeptemberSent->number]);

    $component->set('month', '2026-10');
    expect($numbers($component))->toBe([$initechOctoberSent->number]);

    $component->set('status', '');
    expect($numbers($component))->toBe([$initechOctoberFailed->number, $initechOctoberSent->number]);

    // An unknown value is ignored, and clearing shows everything again.
    $component->set('payer', 'pyr_unknown')->set('month', 'not-a-month');
    expect($numbers($component))->toHaveCount(5);

    $component->set('month', '2026-08');
    expect($numbers($component))->toBe([]);
    $component->assertSee(__('No invoices match the filters'));

    $component->call('clearFilters');
    expect($numbers($component))->toHaveCount(5);

    // The filters live in the URL.
    Livewire::withQueryParams(['status' => InvoiceStatus::FAILED])
        ->actingAs($this->user)
        ->test(InvoiceHistory::class)
        ->assertSet('status', InvoiceStatus::FAILED)
        ->assertSee($initechOctoberFailed->number)
        ->assertDontSee($initechOctoberSent->number);
});

test('invoice history is paginated', function () {
    $payer = Payer::factory()->create();

    foreach (range(1, InvoiceHistory::PER_PAGE + 3) as $day) {
        historyInvoice($payer, '2026-10-01', 'sent');
    }

    $component = Livewire::actingAs($this->user)->test(InvoiceHistory::class);

    expect($component->instance()->invoices->count())->toBe(InvoiceHistory::PER_PAGE)
        ->and($component->instance()->invoices->total())->toBe(InvoiceHistory::PER_PAGE + 3);

    $this->actingAs($this->user)->get(route('invoices.history.index'))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('invoices.history.index'))->assertForbidden();
});

test('pdf download requires invoices module', function () {
    $invoice = historyInvoice(Payer::factory()->create(), '2026-10-01', 'sent');
    Storage::disk(InvoicePdf::disk())->put($invoice->pdf_path, '%PDF-1.7 stored invoice');

    $this->actingAs(User::factory()->withModules([Module::SHOPPING])->create())
        ->get(route('invoices.history.pdf', $invoice))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('invoices.history.pdf', $invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload("invoice-{$invoice->number}.pdf");

    // Not a public file: guests are sent to the login page.
    auth()->logout();
    $this->get(route('invoices.history.pdf', $invoice))->assertRedirect(route('login'));

    // An invoice without a stored PDF is not found.
    $withoutPdf = historyInvoice(Payer::factory()->create(), '2026-10-02', 'pendingReview');

    $this->actingAs($this->user)->get(route('invoices.history.pdf', $withoutPdf))->assertNotFound();
});

test('pdf download reads the configured private disk', function () {
    config(['filesystems.private' => 'r2-private']);
    Storage::fake('r2-private');
    $invoice = historyInvoice(Payer::factory()->create(), '2026-10-01', 'sent');
    Storage::disk('r2-private')->put($invoice->pdf_path, '%PDF-1.7 stored invoice');

    $this->actingAs($this->user)
        ->get(route('invoices.history.pdf', $invoice))
        ->assertOk()
        ->assertDownload("invoice-{$invoice->number}.pdf");
});
