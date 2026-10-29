<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Discard" (US-6.6): an invoice waiting to be sent, or whose sending
 * failed, becomes `cancelled`. It keeps its number, which is never reused,
 * and its PDF. An invoice already sent, cancelled or being sent is refused
 * under `invoice`.
 */
final readonly class CancelInvoice
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the invoice is of a workspace the actor does not see
     * @throws ValidationException
     */
    public function __invoke(User $actor, Invoice $invoice): Invoice
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $invoice);
        $this->ensureCancellable($invoice);

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->ensureCancellable($invoice);

            $invoice->forceFill(['invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::CANCELLED)])->save();

            return $invoice;
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureCancellable(Invoice $invoice): void
    {
        if ((! $invoice->isAwaitingSend() && ! $invoice->isFailed()) || $invoice->hasPendingDelivery()) {
            throw ValidationException::withMessages([
                'invoice' => __('A sent or cancelled invoice cannot be cancelled.'),
            ]);
        }
    }
}
