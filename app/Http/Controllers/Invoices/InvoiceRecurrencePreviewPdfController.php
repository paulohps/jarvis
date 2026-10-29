<?php

namespace App\Http\Controllers\Invoices;

use App\Actions\Invoices\PreviewInvoiceRecurrence;
use App\Http\Controllers\Controller;
use App\Models\InvoiceRecurrence;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * The PDF of the next run of a saved invoice recurrence (US-6.4), marked
 * "PREVIEW" and opened in the browser. Nothing is stored and no invoice
 * number is used. A recurrence of a workspace the user does not see is a
 * 404 (PreviewInvoiceRecurrence).
 */
class InvoiceRecurrencePreviewPdfController extends Controller
{
    public function __invoke(Request $request, InvoiceRecurrence $invoiceRecurrence, PreviewInvoiceRecurrence $previewInvoiceRecurrence): PdfBuilder
    {
        /** @var User $user */
        $user = $request->user();

        $preview = $previewInvoiceRecurrence($user, $invoiceRecurrence);

        return $preview->pdf->inline("invoice-{$preview->number()}.pdf");
    }
}
