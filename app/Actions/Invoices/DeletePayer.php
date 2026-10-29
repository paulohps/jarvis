<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Payer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Removes a payer (US-6.1) with a soft delete: issued invoices keep their
 * snapshot and still point to it. A payer billed by an invoice recurrence
 * that was not deleted — running or paused, since a paused one can be
 * resumed — cannot be removed; the error names how many use it.
 */
final readonly class DeletePayer
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the payer is of a workspace the actor does not see
     * @throws ValidationException when an invoice recurrence uses the payer
     */
    public function __invoke(User $actor, Payer $payer): void
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $payer);

        $recurrences = $payer->invoiceRecurrences()->count();

        if ($recurrences > 0) {
            throw ValidationException::withMessages([
                'payer' => trans_choice(
                    'This payer cannot be deleted because :count invoice recurrence bills it. Delete or change the recurrence first.|This payer cannot be deleted because :count invoice recurrences bill it. Delete or change the recurrences first.',
                    $recurrences,
                ),
            ]);
        }

        $payer->delete();
    }
}
