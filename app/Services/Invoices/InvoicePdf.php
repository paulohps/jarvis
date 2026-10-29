<?php

namespace App\Services\Invoices;

use App\Models\Currency;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * The PDF of an invoice (US-6.4; Workflow 8), drawn by the `invoices.pdf`
 * view in the layout of the original sample invoice and
 * rendered by `spatie/laravel-pdf` (Gotenberg driver).
 *
 * The PDF is always in English, like the sample, whatever the reader's
 * language: the labels are fixed, the dates are `dd/mm/yyyy`, the amount is
 * `2,000.00` and the currency is spelled out in English ("American dollar").
 * A preview carries a "PREVIEW" mark across the page.
 *
 * The PDF of an issued invoice is stored on the private disk (`disk()`) and
 * only reaches a browser through the authorized download route.
 */
final class InvoicePdf
{
    public const string VIEW = 'invoices.pdf';

    public const string LOCALE = 'en';

    /**
     * The private disk the issued PDFs are stored on.
     */
    public static function disk(): string
    {
        return (string) config('filesystems.private');
    }

    /**
     * The PDF of an invoice, saved or not; nothing is rendered until it is
     * saved, downloaded or turned into content.
     */
    public static function for(Invoice $invoice, bool $isPreview = false): PdfBuilder
    {
        return Pdf::view(self::VIEW, self::viewData($invoice, $isPreview))
            ->format(Format::A4)
            ->margins(16, 23, 16, 23)
            ->name("invoice-{$invoice->number}.pdf");
    }

    /**
     * Renders the PDF of an issued invoice and stores it on the private disk,
     * replacing the previous one. Returns its path on the disk.
     */
    public static function store(Invoice $invoice): string
    {
        $path = "invoices/{$invoice->number}.pdf";

        Storage::disk(self::disk())->put($path, self::for($invoice)->generatePdfContent());

        return $path;
    }

    /**
     * What the view prints, already written out.
     *
     * @return array{invoice: Invoice, isPreview: bool, issueDate: string, dueDate: string, amount: string, currencyName: string}
     */
    public static function viewData(Invoice $invoice, bool $isPreview = false): array
    {
        return [
            'invoice' => $invoice,
            'isPreview' => $isPreview,
            'issueDate' => $invoice->issue_date->format(InvoiceTemplateSchema::DATE_FORMAT),
            'dueDate' => $invoice->due_date->format(InvoiceTemplateSchema::DATE_FORMAT),
            'amount' => number_format((float) $invoice->amount, 2, '.', ','),
            'currencyName' => Currency::labelFor($invoice->currency->slug, self::LOCALE),
        ];
    }
}
