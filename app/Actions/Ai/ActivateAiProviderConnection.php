<?php

namespace App\Actions\Ai;

use App\Models\AiProviderConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The super admin puts a connection in use (US-2.3): in one transaction the
 * active one is switched off and this one on, so there is never more than
 * one (the partial unique index is the safety net). Every use of the AI
 * without a connection of its own (AiRoute) goes through it.
 */
final readonly class ActivateAiProviderConnection
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, AiProviderConnection $connection): AiProviderConnection
    {
        Gate::forUser($actor)->authorize('super-admin');

        DB::transaction(function () use ($connection): void {
            AiProviderConnection::query()
                ->active()
                ->whereKeyNot($connection->id)
                ->lockForUpdate()
                ->update(['is_active' => false]);

            // Written by query: the instance may hold a stale `is_active`.
            AiProviderConnection::query()->whereKey($connection->id)->update(['is_active' => true]);

        });

        return $connection->refresh();
    }
}
