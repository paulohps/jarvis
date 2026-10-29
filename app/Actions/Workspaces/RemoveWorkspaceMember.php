<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The super admin takes a user out of a workspace: they stop seeing its
 * data on their next request, and the data stays in the workspace. When it
 * was their default, another of their workspaces (the first by name)
 * becomes the default.
 */
final readonly class RemoveWorkspaceMember
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WorkspaceMembership $membership): void
    {
        Gate::forUser($actor)->authorize('super-admin');

        DB::transaction(function () use ($membership): void {
            $membership->delete();

            if ($membership->is_default) {
                self::promoteAnotherDefault($membership->user_id);
            }
        });

        $membership->user?->forgetMemberships();
    }

    /**
     * Makes the user's first workspace by name their default, when they are
     * left without one.
     */
    public static function promoteAnotherDefault(int $userId): void
    {
        if (WorkspaceMembership::query()->where('user_id', $userId)->where('is_default', true)->exists()) {
            return;
        }

        $next = WorkspaceMembership::query()
            ->select('workspace_user.*')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->where('workspace_user.user_id', $userId)
            ->orderBy('workspaces.name')
            ->orderBy('workspaces.id')
            ->first();

        $next?->forceFill(['is_default' => true])->save();
    }
}
