<?php

namespace App\Actions\Whatsapp;

use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The WhatsApp Actions are for the users with the `whatsapp` module
 * (US-2.1): the same check `module:whatsapp` makes on the routes, repeated in
 * the Action so the web and the API cannot drift apart.
 */
trait AuthorizesWhatsappModule
{
    /**
     * @throws AuthorizationException
     */
    private function authorizeWhatsapp(User $actor): void
    {
        Gate::forUser($actor)->authorize('access-module', Module::WHATSAPP);
    }
}
