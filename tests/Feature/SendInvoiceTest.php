<?php

use App\Actions\Invoices\SendInvoice;
use App\Jobs\SendInvoiceDelivery;
use App\Mail\InvoiceMail;
use App\Models\Currency;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceStatus;
use App\Models\Locale;
use App\Models\MailService;
use App\Models\Module;
use App\Models\User;
use App\Services\Invoices\InvoiceNotifier;
use App\Services\Invoices\InvoicePdf;
use App\Services\Mail\MailServiceMailer;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\InvoiceFailed;
use App\Services\Notifications\Types\InvoiceSent;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Mail\Attachment;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    app(NotificationProvisioner::class)->provisionAll();
    Storage::fake(InvoicePdf::disk());
    Pdf::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 11:05', 'UTC'));

    $this->owner = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'America/Sao_Paulo']);

    $this->invoice = function (array $recurrence = []): Invoice {
        $invoiceRecurrence = InvoiceRecurrence::factory()->for($this->owner, 'createdBy')->create([
            'email_subject' => 'Invoice {% invoice.number %} — {% current_month_name %} {% current_year %}',
            'email_body' => "Hello,\nPlease find attached the invoice {% invoice.number %} of {% invoice.amount %} {% invoice.currency %}.",
            'amount' => '2000.00',
            'currency_id' => Currency::idFor(Currency::USD),
            ...$recurrence,
        ]);

        $invoice = Invoice::factory()->for($invoiceRecurrence, 'recurrence')->create([
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);
        $invoice->recipients()->createMany([['email' => 'ap@initech.example'], ['email' => 'finance@initech.example']]);
        $invoice->forceFill(['pdf_path' => InvoicePdf::store($invoice)])->save();

        return $invoice;
    };
});

test('sending mails every recipient with the pdf attached', function () {
    Mail::fake();
    $invoice = ($this->invoice)();

    $delivery = app(SendInvoice::class)($invoice);

    Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice): bool {
        return $mail->hasTo('ap@initech.example')
            && $mail->hasTo('finance@initech.example')
            && $mail->hasAttachment(
                Attachment::fromStorageDisk(InvoicePdf::disk(), $invoice->pdf_path)
                    ->as("invoice-{$invoice->number}.pdf")
                    ->withMime('application/pdf'),
            )
            && $mail->hasReplyTo($invoice->issuer_email);
    });
    Mail::assertSentCount(1);

    expect($delivery->fresh()->attempts)->toBe(1);
});

test('the invoice goes out through the mail service it was issued with', function () {
    Mail::fake();
    $service = MailService::factory()->for($this->owner)->create();
    $invoice = ($this->invoice)();
    $invoice->forceFill(['mail_service_id' => $service->id])->save();

    app(SendInvoice::class)($invoice);

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->mailer === MailServiceMailer::NAME_PREFIX.$service->id);
    expect(config('mail.mailers.'.MailServiceMailer::NAME_PREFIX.$service->id.'.from.address'))->toBe($service->from_address);
});

test('a mail service the owner may no longer use falls back to the default mailer', function () {
    Mail::fake();
    // Someone else's, no longer global.
    $service = MailService::factory()->create(['is_global' => false]);
    $invoice = ($this->invoice)();
    $invoice->forceFill(['mail_service_id' => $service->id])->save();

    app(SendInvoice::class)($invoice);

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->mailer === config('mail.default'));
});

test('delivery job runs on the invoices queue with three attempts', function () {
    Queue::fake();
    $invoice = ($this->invoice)();
    $actor = User::factory()->withModules([Module::INVOICES])->create();

    $delivery = app(SendInvoice::class)($invoice, $actor);

    expect($delivery->status->slug)->toBe(DeliveryStatus::PENDING)
        ->and($delivery->triggered_by_id)->toBe($actor->id);

    Queue::assertPushedOn('invoices', SendInvoiceDelivery::class, fn (SendInvoiceDelivery $job): bool => $job->delivery->is($delivery));

    $tries = (new ReflectionClass(SendInvoiceDelivery::class))->getAttributes(Tries::class)[0]->newInstance()->tries;

    expect($tries)->toBe(3);
});

test('successful send marks invoice sent and notifies', function () {
    Mail::fake();
    Notification::fake();
    $outsider = User::factory()->create();
    $invoice = ($this->invoice)();

    $delivery = app(SendInvoice::class)($invoice);

    $invoice->refresh();
    $delivery->refresh();

    expect($invoice->status->slug)->toBe(InvoiceStatus::SENT)
        ->and($invoice->sent_at?->toIso8601String())->toBe('2026-10-01T11:05:00+00:00')
        ->and($delivery->status->slug)->toBe(DeliveryStatus::SENT)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->error_message)->toBeNull();

    Notification::assertSentTo($this->owner, InvoiceSent::class, fn (InvoiceSent $notification): bool => $notification->invoice->is($invoice));
    Notification::assertNotSentTo($outsider, InvoiceSent::class);
});

test('definitive failure marks invoice failed and notifies', function () {
    Notification::fake();
    Mail::shouldReceive('to')->andThrow(new TransportException('Connection to smtp refused'));
    $invoice = ($this->invoice)();

    // The sync queue runs a single attempt, then the job's failure handler.
    expect(fn () => app(SendInvoice::class)($invoice))->toThrow(TransportException::class);

    $invoice->refresh();
    $delivery = $invoice->deliveries()->sole();

    expect($invoice->status->slug)->toBe(InvoiceStatus::FAILED)
        ->and($invoice->sent_at)->toBeNull()
        ->and($delivery->status->slug)->toBe(DeliveryStatus::FAILED)
        ->and($delivery->error_message)->toBe('Connection to smtp refused');

    Notification::assertSentTo($this->owner, InvoiceFailed::class, fn (InvoiceFailed $notification): bool => $notification->invoice->is($invoice));
    Notification::assertNotSentTo($this->owner, InvoiceSent::class);
});

test('subject renders template variables', function () {
    Mail::fake();
    $invoice = ($this->invoice)();

    app(SendInvoice::class)($invoice);

    Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice): bool {
        $html = $mail->render();

        return $mail->hasSubject("Invoice {$invoice->number} — October 2026")
            && str_contains($html, "Please find attached the invoice {$invoice->number} of 2,000.00 American dollar.")
            && str_contains($html, 'Hello,<br');
    });
});

test('the e-mail is written in the language picked for the invoice, not the sender language', function () {
    Mail::fake();
    $this->owner->update(['locale_id' => Locale::idFor(Locale::PT_BR)]);
    $english = ($this->invoice)();
    $portuguese = ($this->invoice)(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    app(SendInvoice::class)($english);
    app(SendInvoice::class)($portuguese);

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->invoice->is($english)
        && $mail->hasSubject("Invoice {$english->number} — October 2026")
        && str_contains($mail->render(), 'American dollar'));
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->invoice->is($portuguese)
        && $mail->hasSubject("Invoice {$portuguese->number} — outubro 2026")
        && str_contains($mail->render(), 'Dólar americano'));
});

test('a failing sent notice does not e-mail the client again', function () {
    Mail::fake();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('push channel down'));
    $invoice = ($this->invoice)();

    $delivery = app(SendInvoice::class)($invoice);

    // A retry of the same delivery (the queue redelivering the job) stops.
    (new SendInvoiceDelivery($delivery->fresh()))->handle(app(InvoiceNotifier::class));

    Mail::assertSent(InvoiceMail::class, 1);

    expect($invoice->refresh()->status->slug)->toBe(InvoiceStatus::SENT)
        ->and($delivery->refresh()->status->slug)->toBe(DeliveryStatus::SENT);
});

test('a failed resend keeps a delivered invoice sent', function () {
    Notification::fake();
    $invoice = ($this->invoice)();
    $invoice->forceFill(['invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::SENT), 'sent_at' => now()->subMonth()])->save();
    $resend = InvoiceDelivery::factory()->for($invoice)->create();

    (new SendInvoiceDelivery($resend))->failed(new TransportException('Connection to smtp refused'));

    expect($invoice->refresh()->status->slug)->toBe(InvoiceStatus::SENT)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($resend->refresh()->status->slug)->toBe(DeliveryStatus::FAILED);

    Notification::assertSentTo($this->owner, InvoiceFailed::class);
});
