<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The super admin deletes a workspace that has no shared data (lists,
 * agenda, invoices — deleted ones included); its memberships go with it,
 * and a member for whom it was the default gets another one as default.
 * A workspace with data cannot be deleted: nothing would show it anymore.
 */
final readonly class DeleteWorkspace
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException when the workspace has data
     */
    public function __invoke(User $actor, Workspace $workspace): void
    {
        Gate::forUser($actor)->authorize('super-admin');

        if ($workspace->hasData()) {
            throw ValidationException::withMessages([
                'workspace' => __('A workspace with data cannot be deleted.'),
            ]);
        }

        DB::transaction(function () use ($workspace): void {
            $memberIds = $workspace->memberships()->where('is_default', true)->pluck('user_id');

            $workspace->delete();

            foreach ($memberIds as $userId) {
                RemoveWorkspaceMember::promoteAnotherDefault($userId);
            }
        });
    }
}
