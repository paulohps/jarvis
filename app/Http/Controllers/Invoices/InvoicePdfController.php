<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the stored PDF of an invoice (US-6.8) as `invoice-{number}.pdf`.
 * The file lives on the private disk; this route, behind `module:invoices`,
 * is the only way to it. An invoice without a stored PDF, or of a
 * workspace the user does not see, is a 404.
 */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($invoice->isVisibleTo($user), 404);

        $disk = Storage::disk(InvoicePdf::disk());

        abort_if($invoice->pdf_path === null || ! $disk->exists($invoice->pdf_path), 404);

        return $disk->download($invoice->pdf_path, InvoiceMail::attachmentName($invoice), ['Content-Type' => 'application/pdf']);
    }
}
