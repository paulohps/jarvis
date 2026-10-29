<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Profile\UpdatePreferences;
use App\Actions\Workspaces\UpdateWorkspaceMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user (US-1.5, US-11.1), their language and timezone
 * (US-3.2), through the same `UpdatePreferences` as the profile page, and
 * their own view of their workspaces: the color they see each in and their
 * default.
 */
class MeController extends Controller
{
    /**
     * `GET /api/v1/me`.
     */
    public function show(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * `PATCH /api/v1/me/preferences`: `locale` and/or `timezone`.
     */
    public function updatePreferences(Request $request, UpdatePreferences $updatePreferences): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($updatePreferences($user, $request->only(['locale', 'timezone'])));
    }

    /**
     * `PATCH /api/v1/me/workspaces/{workspace}`: `color` (null: the
     * workspace's) and/or `is_default` (true). 404 for a workspace the user
     * is not in.
     */
    public function updateWorkspace(Request $request, Workspace $workspace, UpdateWorkspaceMember $updateWorkspaceMember): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $user->membershipOf($workspace) ?? abort(404);

        $updateWorkspaceMember($user, $membership, $request->only(['color', 'is_default']));

        $workspaceData = collect(UserResource::workspacesOf($user))->firstWhere('id', $workspace->friendly_id);

        return response()->json(['data' => $workspaceData]);
    }

    /**
     * `GET /api/v1/workspace-colors`: the colors a workspace may be seen in.
     */
    public function workspaceColors(): JsonResponse
    {
        return response()->json([
            'data' => collect(Workspace::COLORS)
                ->map(fn (string $hex, string $name): array => ['name' => $name, 'hex' => $hex])
                ->values()
                ->all(),
        ]);
    }
}
