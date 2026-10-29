<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Workspaces\AddWorkspaceMember;
use App\Actions\Workspaces\RemoveWorkspaceMember;
use App\Actions\Workspaces\SetMemberModules;
use App\Actions\Workspaces\UpdateWorkspaceMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The members of a workspace in the super admin's area of the apps: add an
 * existing user, change their modules, color or default, remove them —
 * through the same Actions as the web admin. `{user}` is the member's
 * friendly id; one who is not a member is a 404.
 */
class WorkspaceMemberController extends Controller
{
    /**
     * `POST /api/v1/admin/workspaces/{workspace}/members`: `user` (friendly
     * id), `modules` (slugs), `color`.
     */
    public function store(Request $request, Workspace $workspace, AddWorkspaceMember $addWorkspaceMember): JsonResponse
    {
        $validated = $request->validate([
            'user' => ['required', 'string', Rule::exists('users', 'friendly_id')->whereNull('deleted_at')],
        ]);

        $user = User::query()->where('friendly_id', $validated['user'])->firstOrFail();
        $membership = $addWorkspaceMember($this->actor($request), $workspace, $user, $request->only(['modules', 'color']));

        return (new WorkspaceMemberResource($membership->fresh(['user', 'modules', 'workspace'])))->response()->setStatusCode(201);
    }

    /**
     * `PATCH /api/v1/admin/workspaces/{workspace}/members/{user}`:
     * `modules`, `color` and/or `is_default`, all in one transaction.
     */
    public function update(Request $request, Workspace $workspace, User $user, SetMemberModules $setMemberModules, UpdateWorkspaceMember $updateWorkspaceMember): WorkspaceMemberResource
    {
        $membership = $this->membership($workspace, $user);
        $actor = $this->actor($request);

        DB::transaction(function () use ($request, $membership, $actor, $setMemberModules, $updateWorkspaceMember): void {
            if ($request->has('modules')) {
                $setMemberModules($actor, $membership, $request->input('modules'));
            }

            $preferences = $request->only(['color', 'is_default']);

            if ($preferences !== []) {
                $updateWorkspaceMember($actor, $membership, $preferences);
            }
        });

        return new WorkspaceMemberResource($membership->fresh(['user', 'modules', 'workspace']));
    }

    /**
     * `DELETE /api/v1/admin/workspaces/{workspace}/members/{user}`.
     */
    public function destroy(Request $request, Workspace $workspace, User $user, RemoveWorkspaceMember $removeWorkspaceMember): Response
    {
        $removeWorkspaceMember($this->actor($request), $this->membership($workspace, $user));

        return response()->noContent();
    }

    private function membership(Workspace $workspace, User $user): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->with(['user', 'modules', 'workspace'])
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
