<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The workspace a shared record belongs to, as its reader sees it: its
 * name and the color they see it in — their membership's own, else the
 * workspace's — by name (Workspace::COLORS) and hex, to tell the
 * workspaces apart in lists and on the agenda.
 *
 * @mixin Workspace
 */
class WorkspaceSummaryResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, color: string, color_hex: string}
     */
    public function toArray(Request $request): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->resource;
        $reader = $request->user();
        $color = $reader instanceof User ? $reader->colorOf($workspace) : $workspace->color;

        return [
            'id' => $workspace->friendly_id,
            'name' => $workspace->name,
            'color' => $color,
            'color_hex' => Workspace::hexOf($color),
        ];
    }
}
