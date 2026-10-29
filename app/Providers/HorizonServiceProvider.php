<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Configure the Horizon authorization services.
     *
     * Unlike the package default, the local environment is not let through:
     * the dashboard is reserved to the super admin everywhere.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Register the Horizon gate, open only to the super admin.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->is_super_admin === true);
    }
}
