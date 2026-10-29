<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\DeleteWorkspace;
use App\Actions\Workspaces\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The workspaces in the super admin's area of the apps, through the same
 * Actions as the web admin.
 */
class WorkspaceController extends Controller
{
    /**
     * `GET /api/v1/admin/workspaces`: every workspace, by name.
     */
    public function index(): AnonymousResourceCollection
    {
        return WorkspaceResource::collection(
            Workspace::query()->withCount('memberships')->orderBy('name')->orderBy('id')->get(),
        );
    }

    /**
     * `POST /api/v1/admin/workspaces`: `name`, `color`.
     */
    public function store(Request $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        $workspace = $createWorkspace($this->actor($request), $request->only(['name', 'color']));

        return (new WorkspaceResource(self::detailed($workspace)))->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/admin/workspaces/{workspace}`: with its members.
     */
    public function show(Workspace $workspace): WorkspaceResource
    {
        return new WorkspaceResource(self::detailed($workspace));
    }

    /**
     * `PATCH /api/v1/admin/workspaces/{workspace}`: `name` and/or `color`.
     */
    public function update(Request $request, Workspace $workspace, UpdateWorkspace $updateWorkspace): WorkspaceResource
    {
        $updateWorkspace($this->actor($request), $workspace, $request->only(['name', 'color']));

        return new WorkspaceResource(self::detailed($workspace));
    }

    /**
     * `DELETE /api/v1/admin/workspaces/{workspace}`: 422 when it has data.
     */
    public function destroy(Request $request, Workspace $workspace, DeleteWorkspace $deleteWorkspace): Response
    {
        $deleteWorkspace($this->actor($request), $workspace);

        return response()->noContent();
    }

    /**
     * The workspace with its members by name, their modules and workspace.
     */
    public static function detailed(Workspace $workspace): Workspace
    {
        return $workspace
            ->loadCount('memberships')
            ->load(['memberships' => fn ($query) => $query
                ->select('workspace_user.*')
                ->join('users', 'users.id', '=', 'workspace_user.user_id')
                ->orderBy('users.name')
                ->with(['user', 'modules', 'workspace'])]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
