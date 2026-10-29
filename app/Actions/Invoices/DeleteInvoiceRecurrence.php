<?php

namespace App\Actions\Invoices;

use App\Actions\Recurrences\PauseRecurrence;
use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\InvoiceRecurrence;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Removes an invoice recurrence (US-6.3) with a soft delete. Its schedule is
 * paused too, so nothing runs for it even if it is restored. The invoices it
 * generated stay, pointing to it.
 */
final readonly class DeleteInvoiceRecurrence
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    public function __construct(private PauseRecurrence $pauseRecurrence) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the recurrence is of a workspace the actor does not see
     */
    public function __invoke(User $actor, InvoiceRecurrence $invoiceRecurrence): void
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $invoiceRecurrence);

        DB::transaction(function () use ($invoiceRecurrence): void {
            if ($invoiceRecurrence->recurrence !== null) {
                ($this->pauseRecurrence)($invoiceRecurrence->recurrence);
            }

            $invoiceRecurrence->delete();
        });
    }
}
