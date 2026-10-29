<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The users in the super admin's area of the apps (US-2.2): the super admin
 * first, then by name, with their workspaces; deactivate and reactivate
 * through the same Actions as the web admin.
 */
class UserController extends Controller
{
    /**
     * `GET /api/v1/admin/users`.
     */
    public function index(): AnonymousResourceCollection
    {
        return AdminUserResource::collection(
            User::query()
                ->with(['memberships.workspace'])
                ->orderByDesc('is_super_admin')
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        );
    }

    /**
     * `POST /api/v1/admin/users/{user}/deactivate`.
     */
    public function deactivate(Request $request, User $user, DeactivateUser $deactivateUser): AdminUserResource
    {
        return new AdminUserResource($deactivateUser($this->actor($request), $user)->load('memberships.workspace'));
    }

    /**
     * `POST /api/v1/admin/users/{user}/reactivate`.
     */
    public function reactivate(Request $request, User $user, ReactivateUser $reactivateUser): AdminUserResource
    {
        return new AdminUserResource($reactivateUser($this->actor($request), $user)->load('memberships.workspace'));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
