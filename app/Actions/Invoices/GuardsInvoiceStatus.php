<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

/**
 * The status checks of the Actions that change an issued invoice (US-6.6,
 * US-6.7), refused under `invoice` like the other module errors. The checks
 * run again on the row locked inside the Action's transaction, so two
 * requests at once cannot both pass.
 */
trait GuardsInvoiceStatus
{
    /**
     * Still before sending and not being sent.
     *
     * @throws ValidationException
     */
    private function ensureAwaitingSend(Invoice $invoice): void
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages([
                'invoice' => __('Only an invoice waiting to be sent can be changed.'),
            ]);
        }
    }

    /**
     * The invoice locked for update, checked again.
     *
     * @throws ValidationException
     */
    private function lockAwaitingSend(Invoice $invoice): Invoice
    {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        $this->ensureAwaitingSend($locked);

        return $locked;
    }
}
