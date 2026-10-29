<?php

namespace App\Http\Resources\Admin;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace in the super admin's area of the apps: its own color (not a
 * member's), how many members it has and, when loaded, the members and
 * whether it has data — a workspace with data cannot be deleted.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->resource;

        return [
            'id' => $workspace->friendly_id,
            'name' => $workspace->name,
            'color' => $workspace->color,
            'color_hex' => Workspace::hexOf($workspace->color),
            'members_count' => $workspace->memberships_count ?? $workspace->memberships()->count(),
            'created_at' => $workspace->created_at?->toIso8601String(),
            'has_data' => $this->when($workspace->relationLoaded('memberships'), fn (): bool => $workspace->hasData()),
            'members' => WorkspaceMemberResource::collection($this->whenLoaded('memberships')),
        ];
    }
}
