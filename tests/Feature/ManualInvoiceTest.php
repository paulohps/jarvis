<?php

use App\Actions\Invoices\CreateOneOffInvoice;
use App\Actions\Invoices\GenerateAndSendNow;
use App\Actions\Invoices\ResendInvoice;
use App\Livewire\Invoices\InvoiceRecurrenceForm;
use App\Livewire\Invoices\InvoiceReview;
use App\Mail\InvoiceMail;
use App\Models\Currency;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceStatus;
use App\Models\Issuer;
use App\Models\Module;
use App\Models\Payer;
use App\Models\Recurrence;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Mail\Attachment;
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
    $this->travelTo(CarbonImmutable::parse('2026-10-15 14:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'America/Sao_Paulo']);

    $this->recurrence = fn (): InvoiceRecurrence => InvoiceRecurrence::factory()
        ->requiresReview()
        ->withRecipients(1)
        ->for($this->user, 'createdBy')
        ->has(Recurrence::factory()->monthly(1)->state([
            'time_of_day' => '08:00',
            'starts_at' => '2026-09-01 00:00:00',
            'next_run_at' => '2026-11-01 11:00:00',
            'last_run_at' => '2026-10-01 11:00:20',
        ]), 'recurrence')
        ->create();
});

test('send now does not change next run', function () {
    $invoiceRecurrence = ($this->recurrence)();

    $invoice = app(GenerateAndSendNow::class)($this->user, $invoiceRecurrence);

    $schedule = $invoiceRecurrence->recurrence()->sole();

    expect($schedule->next_run_at->toIso8601String())->toBe('2026-11-01T11:00:00+00:00')
        ->and($schedule->last_run_at->toIso8601String())->toBe('2026-10-01T11:00:20+00:00')
        ->and($invoice->period_key)->toBeNull()
        ->and($invoice->issue_date->toDateString())->toBe('2026-10-15')
        ->and($invoice->created_by_id)->toBe($this->user->id)
        // Sent at once, even though the recurrence asks for review.
        ->and($invoice->fresh()->status->slug)->toBe(InvoiceStatus::SENT)
        ->and($invoice->deliveries()->sole()->triggered_by_id)->toBe($this->user->id);

    Mail::assertSent(InvoiceMail::class, 1);

    // The scheduled run of November still issues its own invoice.
    $invoiceRecurrence->runScheduledSlot(CarbonImmutable::parse('2026-11-01 11:00', 'UTC'));

    expect($invoiceRecurrence->invoices()->where('period_key', '2026-11')->exists())->toBeTrue();

    // From the recurrence screen, it opens the new invoice.
    $component = Livewire::actingAs($this->user)
        ->test(InvoiceRecurrenceForm::class, ['invoiceRecurrence' => $invoiceRecurrence])
        ->call('sendNow');

    $latest = $invoiceRecurrence->invoices()->latest('id')->first();

    $component->assertRedirect(route('invoices.history.show', $latest));
    expect($latest->period_key)->toBeNull();
});

test('one off invoice gets the next number of its workspace', function () {
    // A recurrence already used the first number of the month.
    Invoice::factory()->create(['number_year_month' => '202610']);

    $payer = Payer::factory()->create(['name' => 'Acme Inc']);
    $issuer = Issuer::factory()->create();
    $input = [
        'payer' => $payer->friendly_id,
        'issuer' => $issuer->friendly_id,
        'recipients' => ['billing@acme.example'],
        'email_subject' => 'Invoice {% invoice.number %}',
        'email_body' => 'Please find the invoice attached.',
        'service_description' => 'Consulting in October.',
        'currency' => Currency::USD,
        'amount' => '750',
        'invoice_date_rule' => InvoiceDateRule::EXECUTION_DAY,
        'invoice_due_date_rule' => InvoiceDueDateRule::DAYS_AFTER_ISSUE,
        'due_date_value' => 15,
        'delivery_mode' => InvoiceDeliveryMode::AUTOMATIC,
    ];

    $invoice = app(CreateOneOffInvoice::class)($this->user, $input);

    expect($invoice->number)->toBe('202610-002')
        ->and($invoice->invoice_recurrence_id)->toBeNull()
        ->and($invoice->period_key)->toBeNull()
        ->and($invoice->payer_name)->toBe('Acme Inc')
        ->and($invoice->amount)->toBe('750.00')
        ->and($invoice->issue_date->toDateString())->toBe('2026-10-15')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-30')
        ->and($invoice->created_by_id)->toBe($this->user->id)
        ->and($invoice->recipients()->pluck('email')->all())->toBe(['billing@acme.example'])
        ->and($invoice->fresh()->status->slug)->toBe(InvoiceStatus::SENT);
    Storage::disk(InvoicePdf::disk())->assertExists($invoice->pdf_path);
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->hasTo('billing@acme.example'));

    // In review mode it waits for approval with the following number.
    $review = app(CreateOneOffInvoice::class)($this->user, [...$input, 'delivery_mode' => InvoiceDeliveryMode::REQUIRES_REVIEW]);

    expect($review->number)->toBe('202610-003')
        ->and($review->isPendingReview())->toBeTrue();
    Mail::assertSentCount(1);

    // The fields are validated like a recurrence's.
    $errors = rescue(
        fn () => app(CreateOneOffInvoice::class)($this->user, [...$input, 'recipients' => [], 'amount' => '-1', 'email_subject' => '{% unknown %}']),
        fn (ValidationException $exception): array => $exception->errors(),
        report: false,
    );

    expect($errors)->toHaveKeys(['recipients', 'amount', 'email_subject'])
        ->and(Invoice::query()->count())->toBe(3);
});

test('resend reuses number and pdf and logs a delivery', function () {
    $invoice = Invoice::factory()->oneOff()->sent()->create();
    $invoice->recipients()->create(['email' => 'ap@initech.example']);
    $invoice->deliveries()->create(['delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::SENT), 'attempts' => 1, 'sent_at' => now()->subDay()]);
    Storage::disk(InvoicePdf::disk())->put($invoice->pdf_path, 'the original pdf');
    $number = $invoice->number;
    $pdfPath = $invoice->pdf_path;
    $actor = User::factory()->withModules([Module::INVOICES])->create();

    $delivery = app(ResendInvoice::class)($actor, $invoice);

    $invoice->refresh();

    expect($invoice->number)->toBe($number)
        ->and($invoice->pdf_path)->toBe($pdfPath)
        ->and(Storage::disk(InvoicePdf::disk())->get($pdfPath))->toBe('the original pdf')
        ->and($invoice->deliveries()->count())->toBe(2)
        ->and($delivery->triggered_by_id)->toBe($actor->id)
        ->and($delivery->fresh()->status->slug)->toBe(DeliveryStatus::SENT)
        ->and($invoice->isSent())->toBeTrue();

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->invoice->is($invoice)
        && $mail->hasAttachment(Attachment::fromStorageDisk(InvoicePdf::disk(), $pdfPath)->as("invoice-{$number}.pdf")->withMime('application/pdf')));

    // A failed invoice is resent from its screen; a pending one is not.
    $failed = Invoice::factory()->oneOff()->failed()->create();
    $failed->recipients()->create(['email' => 'ap@initech.example']);
    Storage::disk(InvoicePdf::disk())->put($failed->pdf_path, 'pdf');

    Livewire::actingAs($actor)
        ->test(InvoiceReview::class, ['invoice' => $failed])
        ->call('resend')
        ->assertHasNoErrors();

    expect($failed->fresh()->isSent())->toBeTrue();

    $pending = Invoice::factory()->oneOff()->pendingReview()->create();

    expect(fn () => app(ResendInvoice::class)($actor, $pending))->toThrow(ValidationException::class);
});
