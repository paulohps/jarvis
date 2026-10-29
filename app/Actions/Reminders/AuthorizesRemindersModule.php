<?php

namespace App\Actions\Reminders;

use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The reminders Actions are for the users with the `reminders` module
 * (US-2.1): the same check `module:reminders` makes on the routes, repeated
 * in the Action so the web and the API cannot drift apart.
 */
trait AuthorizesRemindersModule
{
    /**
     * @throws AuthorizationException
     */
    private function authorizeReminders(User $actor): void
    {
        Gate::forUser($actor)->authorize('access-module', Module::REMINDERS);
    }
}
