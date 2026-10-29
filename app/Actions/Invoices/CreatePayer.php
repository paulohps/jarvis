<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Module;
use App\Models\Payer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Registers who invoices are billed to (US-6.1): a name up to 255
 * characters and the multiline "Bill to" address, both required. A payer
 * is shared by the members of its workspace with the invoices module: the
 * optional `workspace` (friendly id) of the input, else the actor's default
 * for the module (ResolvesWorkspace). The workspace never changes after.
 */
final readonly class CreatePayer
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @param  array{name?: mixed, address?: mixed, workspace?: mixed}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): Payer
    {
        $this->authorizeInvoices($actor);

        $workspace = $this->workspaceFor($actor, $input, Module::INVOICES);

        return Payer::query()->create([
            ...PayerRules::validate($input),
            'workspace_id' => $workspace->id,
            'created_by_id' => $actor->id,
        ]);
    }
}
