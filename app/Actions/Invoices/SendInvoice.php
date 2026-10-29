<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Jobs\SendInvoiceDelivery;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Sends an invoice to its recipients (US-6.5, US-6.7): records a `pending`
 * `invoice_deliveries` row — with who asked for it, or none for the
 * scheduler — and queues SendInvoiceDelivery on the `invoices` queue, which
 * e-mails it and settles the delivery and the invoice as `sent` or `failed`.
 *
 * It sends whatever invoice it is given; the Actions in front of it decide
 * which ones may be sent (approval, resend).
 */
final readonly class SendInvoice
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the user asking is not of the invoice's workspace
     */
    public function __invoke(Invoice $invoice, ?User $triggeredBy = null): InvoiceDelivery
    {
        if ($triggeredBy !== null) {
            $this->authorizeInvoices($triggeredBy);
            $this->authorizeWorkspaceRecord($triggeredBy, $invoice);
        }

        $delivery = $invoice->deliveries()->create([
            'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::PENDING),
            'triggered_by_id' => $triggeredBy?->id,
            'attempts' => 0,
        ]);

        SendInvoiceDelivery::dispatch($delivery)->afterCommit();

        return $delivery;
    }
}
