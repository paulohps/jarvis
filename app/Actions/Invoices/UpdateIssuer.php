<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Issuer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Edits an issuer (US-6.2) with the rules of `CreateIssuer`. Invoices
 * already issued keep their snapshot of every field, so their PDFs do not
 * change. Its workspace stays the one it was created in.
 */
final readonly class UpdateIssuer
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the issuer is of a workspace the actor does not see
     * @throws ValidationException
     */
    public function __invoke(User $actor, Issuer $issuer, array $input): Issuer
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $issuer);

        $issuer->update(IssuerRules::validate($input));

        return $issuer;
    }
}
