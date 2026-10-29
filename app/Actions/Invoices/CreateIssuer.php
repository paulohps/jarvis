<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Issuer;
use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Registers who issues invoices (US-6.2): name, e-mail, postal code, street
 * and number, district, city and state, all required. There may be any
 * number of issuers. An issuer is shared by the members of its workspace
 * with the invoices module: the optional `workspace` (friendly id) of the
 * input, else the actor's default for the module (ResolvesWorkspace). The
 * workspace never changes after.
 */
final readonly class CreateIssuer
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): Issuer
    {
        $this->authorizeInvoices($actor);

        $workspace = $this->workspaceFor($actor, $input, Module::INVOICES);

        return Issuer::query()->create([
            ...IssuerRules::validate($input),
            'workspace_id' => $workspace->id,
            'created_by_id' => $actor->id,
        ]);
    }
}
