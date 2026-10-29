<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Payer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Edits a payer (US-6.1) with the rules of `CreatePayer`. Invoices already
 * issued keep their snapshot of the name and address, so their PDFs do not
 * change. Its workspace stays the one it was created in.
 */
final readonly class UpdatePayer
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @param  array{name?: mixed, address?: mixed}  $input
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the payer is of a workspace the actor does not see
     * @throws ValidationException
     */
    public function __invoke(User $actor, Payer $payer, array $input): Payer
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $payer);

        $payer->update(PayerRules::validate($input));

        return $payer;
    }
}
