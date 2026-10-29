<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * "Generate and send now" on an invoice recurrence (US-6.7): issues an
 * invoice with the recurrence's rules applied to the current date, without
 * a period key, and sends it on behalf of the user whatever the delivery
 * mode (asking for it is the approval). The schedule is not touched: the
 * next run stays when it was, and its period is still issued then.
 */
final readonly class GenerateAndSendNow
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    public function __construct(
        private GenerateInvoice $generateInvoice,
        private SendInvoice $sendInvoice,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the recurrence is of a workspace the actor does not see
     */
    public function __invoke(User $actor, InvoiceRecurrence $invoiceRecurrence): Invoice
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $invoiceRecurrence);

        $invoice = ($this->generateInvoice)($invoiceRecurrence, now()->toImmutable(), null, $actor);

        $invoice->forceFill(['approved_by_id' => $actor->id])->save();

        ($this->sendInvoice)($invoice, $actor);

        return $invoice;
    }
}
