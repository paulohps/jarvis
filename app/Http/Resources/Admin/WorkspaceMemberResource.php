<?php

namespace App\Http\Resources\Admin;

use App\Models\Module;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member of a workspace in the super admin's area: who they are, the
 * color they chose for it (null: the workspace's) and the one they end up
 * seeing, whether it is their default and the modules on for them there —
 * every active one for the super admin, who needs no rows.
 *
 * @mixin WorkspaceMembership
 */
class WorkspaceMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkspaceMembership $membership */
        $membership = $this->resource;
        $user = $membership->user;
        $color = $membership->effectiveColor();

        return [
            'user' => [
                'id' => $user->friendly_id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'is_super_admin' => $user->isSuperAdmin(),
                'is_deactivated' => $user->isDeactivated(),
            ],
            'color' => $membership->color,
            'effective_color' => $color,
            'effective_color_hex' => Workspace::hexOf($color),
            'is_default' => $membership->is_default,
            'modules' => $user->isSuperAdmin()
                ? Module::query()->active()->orderBy('sort_order')->pluck('slug')->all()
                : $membership->modules->sortBy('sort_order')->pluck('slug')->values()->all(),
        ];
    }
}
