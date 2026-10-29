<?php

namespace App\Actions\Ai;

use App\Models\AiProviderConnection;
use App\Models\AiPurposeAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The super admin removes a connection (soft delete): the AI usage it served
 * keeps it (AiUsage::connection()), its key is dropped, and the uses of the
 * AI that had it go back to the connection in use. Removing the connection
 * in use leaves none in use until another is put in use.
 */
final readonly class DeleteAiProviderConnection
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, AiProviderConnection $connection): AiProviderConnection
    {
        Gate::forUser($actor)->authorize('super-admin');

        DB::transaction(function () use ($connection): void {
            AiPurposeAssignment::query()->where('ai_provider_connection_id', $connection->id)->delete();

            // Off before going, so the single-active index frees its slot.
            $connection->forceFill(['is_active' => false, 'api_key' => null])->save();
            $connection->delete();
        });

        return $connection;
    }
}
