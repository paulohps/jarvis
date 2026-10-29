<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Services\Templates\TemplatePreview;
use Carbon\CarbonImmutable;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * What the next run of an invoice recurrence would issue and send (US-6.4),
 * from PreviewInvoiceRecurrence: the run instant, the unsaved invoice with
 * its dates and provisional `YYYYMM-PREVIEW` number, the recipients, the
 * rendered e-mail and the PDF marked "PREVIEW" (not rendered until used).
 */
final readonly class InvoicePreview
{
    /**
     * @param  CarbonImmutable|null  $runAt  UTC; null when the schedule has no run left (the dates are then today's)
     * @param  list<string>  $recipients
     */
    public function __construct(
        public ?CarbonImmutable $runAt,
        public InvoiceDates $dates,
        public Invoice $invoice,
        public array $recipients,
        public TemplatePreview $subject,
        public TemplatePreview $body,
        public PdfBuilder $pdf,
    ) {}

    public function number(): string
    {
        return $this->invoice->number;
    }

    /**
     * The HTML the PDF is printed from, to show it on screen.
     */
    public function pdfHtml(): string
    {
        return $this->pdf->getHtml();
    }
}
