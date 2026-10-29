<?php

namespace App\Http\Resources\Admin;

use App\Models\Invitation;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation in the super admin's area of the apps: the address, its
 * status, the workspace the user joins (null for invitations made before
 * workspaces), the modules switched on there and the language.
 *
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Invitation $invitation */
        $invitation = $this->resource;
        $workspace = $invitation->workspace;

        return [
            'id' => $invitation->friendly_id,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'workspace' => $workspace === null ? null : [
                'id' => $workspace->friendly_id,
                'name' => $workspace->name,
                'color' => $workspace->color,
                'color_hex' => Workspace::hexOf($workspace->color),
            ],
            'modules' => $invitation->modules->sortBy('sort_order')->pluck('slug')->values()->all(),
            'locale' => $invitation->locale->slug,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
