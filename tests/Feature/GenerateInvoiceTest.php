<?php

use App\Actions\Invoices\GenerateInvoice;
use App\Models\Invoice;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceNumberSequence;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceStatus;
use App\Models\Issuer;
use App\Models\Module;
use App\Models\Payer;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake(InvoicePdf::disk());
    Pdf::fake();

    $this->runAt = CarbonImmutable::parse('2026-10-01 11:00', 'UTC');
    $this->owner = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $this->payer = Payer::factory()->create(['name' => 'Initech Corp', 'address' => "PO Box 1234\nSpringfield, IL 62701"]);
    $this->issuer = Issuer::factory()->create(['name' => 'Jane Doe Consulting Ltda', 'city' => 'São Paulo', 'state' => 'SP']);

    $this->recurrence = fn (string $state = 'automatic'): InvoiceRecurrence => InvoiceRecurrence::factory()
        ->{$state}()
        ->withRecipients(2)
        ->for($this->owner, 'createdBy')
        ->for($this->payer)
        ->for($this->issuer)
        ->create([
            'amount' => '2000.00',
            'invoice_due_date_rule_id' => InvoiceDueDateRule::idFor(InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH),
            'due_date_value' => null,
        ]);
});

test('generating twice for the same period returns the same invoice', function () {
    $invoiceRecurrence = ($this->recurrence)();

    $first = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt, '2026-10');
    $second = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt->addHour(), '2026-10');

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->is($first))->toBeTrue()
        ->and(Invoice::query()->count())->toBe(1)
        ->and($first->number)->toBe('202610-001')
        ->and(InvoiceNumberSequence::query()->where('year_month', '202610')->value('last_number'))->toBe(1);

    // Another period is another invoice with the next number.
    $november = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt->addMonth(), '2026-11');

    expect($november->number)->toBe('202611-001')
        ->and(Invoice::query()->count())->toBe(2);
});

test('generated invoice snapshots payer and issuer', function () {
    $invoiceRecurrence = ($this->recurrence)();

    $invoice = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt, '2026-10');

    $this->payer->update(['name' => 'Renamed Corp', 'address' => 'Elsewhere']);
    $this->issuer->update(['name' => 'Renamed Issuer']);

    $invoice->refresh();

    expect($invoice->payer_name)->toBe('Initech Corp')
        ->and($invoice->payer_address)->toBe("PO Box 1234\nSpringfield, IL 62701")
        ->and($invoice->issuer_name)->toBe('Jane Doe Consulting Ltda')
        ->and($invoice->issuer_city)->toBe('São Paulo')
        ->and($invoice->issuer_state)->toBe('SP')
        ->and($invoice->payer_id)->toBe($this->payer->id)
        ->and($invoice->issuer_id)->toBe($this->issuer->id)
        // Dates of a run at 08:00 in São Paulo on October 1st.
        ->and($invoice->issue_date->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-31')
        ->and($invoice->amount)->toBe('2000.00')
        ->and($invoice->email_subject)->toBe($invoiceRecurrence->email_subject)
        ->and($invoice->email_body)->toBe($invoiceRecurrence->email_body)
        ->and($invoice->period_key)->toBe('2026-10')
        ->and($invoice->created_by_id)->toBeNull()
        ->and($invoice->recipients()->pluck('email')->sort()->values()->all())
        ->toBe($invoiceRecurrence->recipients()->pluck('email')->sort()->values()->all())
        // Automatic sending starts as a draft, sent right after.
        ->and($invoice->status->slug)->toBe(InvoiceStatus::DRAFT);

    // The PDF is stored on the private disk.
    expect($invoice->pdf_path)->toBe("invoices/{$invoice->workspace->friendly_id}/202610-001.pdf");
    Storage::disk(InvoicePdf::disk())->assertExists($invoice->pdf_path);
    Pdf::assertViewIs(InvoicePdf::VIEW);
});

test('review mode creates pending review invoice', function () {
    $invoice = app(GenerateInvoice::class)(($this->recurrence)('requiresReview'), $this->runAt, '2026-10');

    expect($invoice->status->slug)->toBe(InvoiceStatus::PENDING_REVIEW)
        ->and($invoice->isPendingReview())->toBeTrue()
        ->and($invoice->sent_at)->toBeNull();
});

test('manual runs without a period key always issue a new invoice', function () {
    $invoiceRecurrence = ($this->recurrence)();
    $actor = User::factory()->withModules([Module::INVOICES])->create();

    $first = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt, null, $actor);
    $second = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt, null, $actor);

    expect([$first->number, $second->number])->toBe(['202610-001', '202610-002'])
        ->and($first->period_key)->toBeNull()
        ->and($first->created_by_id)->toBe($actor->id);
});

test('a pdf failure keeps the invoice and its number', function () {
    $invoiceRecurrence = ($this->recurrence)();
    Pdf::shouldReceive('view')->once()->andThrow(new RuntimeException('Gotenberg timed out'));

    expect(fn () => app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt, '2026-10'))
        ->toThrow(RuntimeException::class, 'Gotenberg timed out');

    $invoice = Invoice::query()->sole();

    expect($invoice->number)->toBe('202610-001')
        ->and($invoice->pdf_path)->toBeNull();

    // The retry of the same period renders the missing PDF.
    Pdf::fake();
    $retried = app(GenerateInvoice::class)($invoiceRecurrence, $this->runAt, '2026-10');

    expect($retried->is($invoice))->toBeTrue()
        ->and($retried->pdf_path)->toBe("invoices/{$invoice->workspace->friendly_id}/202610-001.pdf");
    Storage::disk(InvoicePdf::disk())->assertExists($retried->pdf_path);
});
