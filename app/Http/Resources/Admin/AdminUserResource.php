<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user in the super admin's area of the apps: who they are, whether they
 * are deactivated, when they were last seen and their workspaces, each in
 * the color the user sees it in.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->friendly_id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_deactivated' => $user->isDeactivated(),
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            'workspaces' => $user->memberships
                ->map(fn (WorkspaceMembership $membership): array => [
                    'id' => $membership->workspace->friendly_id,
                    'name' => $membership->workspace->name,
                    'color' => $membership->effectiveColor(),
                    'color_hex' => Workspace::hexOf($membership->effectiveColor()),
                ])
                ->values()
                ->all(),
        ];
    }
}
