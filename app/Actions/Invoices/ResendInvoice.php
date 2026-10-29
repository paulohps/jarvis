<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Send again" (US-6.7): e-mails an invoice already `sent` or `failed` once
 * more — the same number and the same stored PDF, nothing is issued or
 * rendered — through a new `invoice_deliveries` row with who asked for it.
 * Any other invoice, or one still being sent, is refused under `invoice`.
 */
final readonly class ResendInvoice
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    public function __construct(private SendInvoice $sendInvoice) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the invoice is of a workspace the actor does not see
     * @throws ValidationException
     */
    public function __invoke(User $actor, Invoice $invoice): InvoiceDelivery
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $invoice);
        $this->ensureResendable($invoice);

        return DB::transaction(function () use ($actor, $invoice): InvoiceDelivery {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->ensureResendable($invoice);

            return ($this->sendInvoice)($invoice, $actor);
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureResendable(Invoice $invoice): void
    {
        if (! $invoice->isResendable() || $invoice->hasPendingDelivery()) {
            throw ValidationException::withMessages([
                'invoice' => __('Only a sent or failed invoice can be sent again.'),
            ]);
        }
    }
}
