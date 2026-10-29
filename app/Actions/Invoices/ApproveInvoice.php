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
 * "Approve and send" (US-6.6): records who approved an invoice waiting to
 * be sent (`approved_by_id`) and sends it through SendInvoice on their
 * behalf. An invoice already sent, cancelled or being sent is refused under
 * `invoice`.
 */
final readonly class ApproveInvoice
{
    use AuthorizesInvoicesModule;
    use GuardsInvoiceStatus;
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
        $this->ensureAwaitingSend($invoice);

        return DB::transaction(function () use ($actor, $invoice): InvoiceDelivery {
            $invoice = $this->lockAwaitingSend($invoice);

            $invoice->forceFill(['approved_by_id' => $actor->id])->save();

            return ($this->sendInvoice)($invoice, $actor);
        });
    }
}
