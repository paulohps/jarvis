<?php

namespace App\Actions\Invoices;

use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The invoices Actions are for the users with the `invoices` module (US-2.1):
 * the same check `module:invoices` makes on the routes, repeated in the
 * Action so the web and the API cannot drift apart.
 */
trait AuthorizesInvoicesModule
{
    /**
     * @throws AuthorizationException
     */
    private function authorizeInvoices(User $actor): void
    {
        Gate::forUser($actor)->authorize('access-module', Module::INVOICES);
    }
}
