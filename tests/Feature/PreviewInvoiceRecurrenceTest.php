<?php

use App\Actions\Invoices\PreviewInvoiceRecurrence;
use App\Livewire\Invoices\InvoiceRecurrenceForm;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceNumberSequence;
use App\Models\InvoiceRecurrence;
use App\Models\Issuer;
use App\Models\Locale;
use App\Models\Module;
use App\Models\Payer;
use App\Models\Recurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));
    Pdf::fake();

    $this->user = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'America/Sao_Paulo']);

    // Monthly on day 1 at 08:00 São Paulo, issued on the execution day and due on the last day of the month.
    $this->invoiceRecurrence = InvoiceRecurrence::factory()
        ->withRecipients(1)
        ->has(Recurrence::factory()->monthly(1)->state(['time_of_day' => '08:00', 'timezone' => 'America/Sao_Paulo']), 'recurrence')
        ->create([
            'created_by_id' => $this->user->id,
            'payer_id' => Payer::factory()->create(['name' => 'Initech Corp'])->id,
            'issuer_id' => Issuer::factory()->create(['name' => 'Jane Doe Consulting Ltda'])->id,
            'currency_id' => Currency::idFor(Currency::USD),
            'amount' => '2000.00',
            'email_subject' => 'Invoice {% invoice.number %} — {% current_month_name %} {% current_year %}',
            'email_body' => '<p>{% invoice.currency %} {% invoice.amount %}, due on {% invoice.due_date %}.</p><script>alert(1)</script>',
            'invoice_date_rule_id' => InvoiceDateRule::idFor(InvoiceDateRule::EXECUTION_DAY),
            'invoice_due_date_rule_id' => InvoiceDueDateRule::idFor(InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH),
            'due_date_value' => null,
        ]);
});

test('preview does not consume invoice numbers', function () {
    // November already has invoices.
    InvoiceNumberSequence::query()->create(['workspace_id' => $this->invoiceRecurrence->workspace_id, 'year_month' => '202611', 'last_number' => 3]);
    Invoice::factory()->oneOff()->create();

    $sequencesBefore = InvoiceNumberSequence::query()->orderBy('year_month')->get(['year_month', 'last_number'])->toArray();
    $invoicesBefore = Invoice::query()->count();

    $preview = app(PreviewInvoiceRecurrence::class)($this->user, $this->invoiceRecurrence);

    expect($preview->number())->toBe('202611-PREVIEW')
        ->and($preview->invoice->exists)->toBeFalse()
        ->and($preview->pdf)->toBeInstanceOf(PdfBuilder::class)
        ->and($preview->pdfHtml())->toContain('PREVIEW')
        ->and($preview->pdfHtml())->toContain('202611-PREVIEW');

    // The PDF route of a saved recurrence renders it without touching the numbering either.
    $this->actingAs($this->user)
        ->get(route('invoices.recurrences.preview-pdf', $this->invoiceRecurrence))
        ->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->viewName === 'invoices.pdf'
        && $pdf->viewData['isPreview'] === true
        && $pdf->downloadName === 'invoice-202611-PREVIEW.pdf'
        && $pdf->isInline());

    // And the screen's preview neither.
    Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class, ['invoiceRecurrence' => $this->invoiceRecurrence])
        ->call('preview')
        ->assertSee('202611-PREVIEW');

    expect(InvoiceNumberSequence::query()->orderBy('year_month')->get(['year_month', 'last_number'])->toArray())->toBe($sequencesBefore)
        ->and(Invoice::query()->count())->toBe($invoicesBefore);

    // Only for users with the module.
    $outsider = User::factory()->withModules([Module::REMINDERS])->create();

    expect(fn () => app(PreviewInvoiceRecurrence::class)($outsider, $this->invoiceRecurrence))->toThrow(AuthorizationException::class);
    $this->actingAs($outsider)->get(route('invoices.recurrences.preview-pdf', $this->invoiceRecurrence))->assertForbidden();
});

test('preview uses next run dates', function () {
    $preview = app(PreviewInvoiceRecurrence::class)($this->user, $this->invoiceRecurrence);

    // Saved on October 15th, the next run is November 1st at 08:00 São Paulo.
    expect($preview->runAt?->toDateTimeString())->toBe('2026-11-01 11:00:00')
        ->and($preview->dates->issueDate->format('d/m/Y'))->toBe('01/11/2026')
        ->and($preview->dates->dueDate->format('d/m/Y'))->toBe('30/11/2026')
        ->and($preview->recipients)->toBe($this->invoiceRecurrence->recipients()->pluck('email')->all())
        ->and($preview->subject->output)->toBe('Invoice 202611-PREVIEW — November 2026')
        ->and($preview->body->output)->toContain('American dollar 2,000.00, due on 30/11/2026.')
        ->and($preview->body->output)->not->toContain('<script>');

    $html = $preview->pdfHtml();

    expect($html)->toContain('01/11/2026')
        ->and($html)->toContain('30/11/2026')
        ->and($html)->toContain('2,000.00');

    // The e-mail follows the language picked for the recurrence, not the
    // user's; the PDF stays in English.
    $this->user->update(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    expect(app(PreviewInvoiceRecurrence::class)($this->user->fresh(), $this->invoiceRecurrence->fresh())->subject->output)
        ->toBe('Invoice 202611-PREVIEW — November 2026');

    $this->invoiceRecurrence->update(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    $portuguese = app(PreviewInvoiceRecurrence::class)($this->user->fresh(), $this->invoiceRecurrence->fresh());

    expect($portuguese->subject->output)->toBe('Invoice 202611-PREVIEW — novembro 2026')
        ->and($portuguese->body->output)->toContain('Dólar americano')
        ->and($portuguese->pdfHtml())->toContain('American dollar');

    // A paused recurrence previews the run it would have if resumed now.
    $this->invoiceRecurrence->recurrence->update(['is_paused' => true]);

    expect(app(PreviewInvoiceRecurrence::class)($this->user, $this->invoiceRecurrence->fresh())->number())->toBe('202611-PREVIEW');

    // The form previews what is filled in, before saving: day 10 of the
    // month, due 15 days later.
    $form = Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class)
        ->call('preview')
        ->assertSee('data-invoice-preview-incomplete', false)
        ->set('payer', $this->invoiceRecurrence->payer->friendly_id)
        ->set('issuer', $this->invoiceRecurrence->issuer->friendly_id)
        ->set('amount', '1500')
        ->set('invoice_date_rule', InvoiceDateRule::DAY_OF_CURRENT_MONTH)
        ->set('invoice_date_day', 10)
        ->set('invoice_due_date_rule', InvoiceDueDateRule::DAYS_AFTER_ISSUE)
        ->set('due_date_value', 15)
        ->set('recipients.0', 'ap@example.com')
        ->call('preview')
        ->assertDontSee('data-invoice-preview-incomplete', false);

    expect($form->html())->toContain('data-invoice-preview-number>202611-PREVIEW<')
        ->toContain('data-invoice-preview-issue-date>10/11/2026<')
        ->toContain('data-invoice-preview-due-date>25/11/2026<')
        ->toContain('ap@example.com')
        ->toContain('American dollar 1,500.00, due on 25/11/2026');

    $form->call('closePreview')->assertDontSee('202611-PREVIEW');

    expect(InvoiceRecurrence::query()->count())->toBe(1)
        ->and(InvoiceNumberSequence::query()->count())->toBe(0);
});
