<?php

namespace App\Jobs;

use App\Mail\InvoiceMail;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceStatus;
use App\Models\MailService;
use App\Services\Invoices\InvoiceNotifier;
use App\Services\Invoices\InvoicePdf;
use App\Services\Mail\MailServiceMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * E-mails an invoice for one `invoice_deliveries` row (US-6.5; Workflow 8),
 * on the `invoices` queue with 3 attempts.
 *
 * One `InvoiceMail` goes to all the invoice's recipients with the PDF
 * attached (rendered again first if the stored file is gone), through the
 * mail service the invoice was issued with (the default mailer without
 * one). On success
 * the delivery is `sent`, the invoice is `sent` with `sent_at`, and
 * `invoice_sent` is notified. When the last attempt fails, the delivery is
 * `failed` with the error, the invoice is `failed`, and `invoice_failed` is
 * notified.
 */
#[Tries(3)]
#[Backoff(30, 120)]
#[DeleteWhenMissingModels]
class SendInvoiceDelivery implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'invoices';

    public function __construct(public InvoiceDelivery $delivery)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(InvoiceNotifier $notifier): void
    {
        $this->delivery->refresh();

        if ($this->delivery->delivery_status_id !== DeliveryStatus::idFor(DeliveryStatus::PENDING)) {
            return;
        }

        $invoice = $this->delivery->invoice;

        $this->delivery->forceFill(['attempts' => $this->attempts()])->save();

        $emails = $invoice->recipients()->orderBy('id')->pluck('email')->all();

        if ($emails === []) {
            throw new RuntimeException("Invoice {$invoice->number} has no recipients.");
        }

        if ($invoice->pdf_path === null || ! Storage::disk(InvoicePdf::disk())->exists($invoice->pdf_path)) {
            $invoice->forceFill(['pdf_path' => InvoicePdf::store($invoice)])->save();
        }

        $mailService = $this->usableMailService($invoice);
        $pendingMail = $mailService === null ? Mail::to($emails) : MailServiceMailer::for($mailService)->to($emails);

        $pendingMail->send(new InvoiceMail($invoice));

        DB::transaction(function () use ($invoice): void {
            $this->delivery->forceFill([
                'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::SENT),
                'error_message' => null,
                'sent_at' => now(),
            ])->save();

            $invoice->forceFill([
                'invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::SENT),
                'sent_at' => now(),
            ])->save();
        });

        try {
            $notifier->sent($invoice);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The invoice's mail service while its owner (who issued it, or the
     * recurrence's owner) may still send through it: one that stopped being
     * global falls back to the default mailer.
     */
    private function usableMailService(Invoice $invoice): ?MailService
    {
        $mailService = $invoice->mailService;
        $owner = $invoice->createdBy ?? $invoice->recurrence?->createdBy;

        if ($mailService === null || $owner === null) {
            return $mailService;
        }

        return $mailService->isUsableBy($owner) ? $mailService : null;
    }

    /**
     * The last attempt failed.
     */
    public function failed(?Throwable $exception): void
    {
        $invoice = $this->delivery->invoice;

        DB::transaction(function () use ($invoice, $exception): void {
            $this->delivery->forceFill([
                'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::FAILED),
                'error_message' => $exception?->getMessage() ?? __('The invoice could not be sent.'),
            ])->save();

            if ($invoice->sent_at === null) {
                $invoice->forceFill(['invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::FAILED)])->save();
            }
        });

        app(InvoiceNotifier::class)->failed($invoice);
    }
}
