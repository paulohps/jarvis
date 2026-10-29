<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Issuer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Removes an issuer (US-6.2) with a soft delete, under the rule of
 * `DeletePayer`: refused while an invoice recurrence that was not deleted
 * (running or paused) uses it.
 */
final readonly class DeleteIssuer
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the issuer is of a workspace the actor does not see
     * @throws ValidationException when an invoice recurrence uses the issuer
     */
    public function __invoke(User $actor, Issuer $issuer): void
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $issuer);

        $recurrences = $issuer->invoiceRecurrences()->count();

        if ($recurrences > 0) {
            throw ValidationException::withMessages([
                'issuer' => trans_choice(
                    'This issuer cannot be deleted because :count invoice recurrence uses it. Delete or change the recurrence first.|This issuer cannot be deleted because :count invoice recurrences use it. Delete or change the recurrences first.',
                    $recurrences,
                ),
            ]);
        }

        $issuer->delete();
    }
}
