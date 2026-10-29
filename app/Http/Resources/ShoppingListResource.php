<?php

namespace App\Http\Resources;

use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shopping list (US-8.1): the workspace it belongs to, its name, whether
 * it is archived, whether it is its workspace's default for voice commands,
 * how many items it has and how many are still to pick (when counted) and
 * its items in display order (when loaded). Its private channel
 * (`shopping-lists.{friendly_id}`) brings the changes made by anyone of its
 * workspace (US-8.2).
 *
 * @mixin ShoppingList
 */
class ShoppingListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShoppingList $list */
        $list = $this->resource;

        return [
            'friendly_id' => $list->friendly_id,
            'name' => $list->name,
            'workspace' => new WorkspaceSummaryResource($list->workspace),
            'is_archived' => $list->isArchived(),
            'archived_at' => $list->archived_at?->toIso8601String(),
            'is_default' => $list->is_default,
            'is_recurring' => $list->shopping_list_schedule_id !== null,
            'schedule_id' => $list->schedule?->friendly_id,
            'due_at' => $list->due_at?->toIso8601String(),
            'is_overdue' => $list->isOverdue(),
            'completed_at' => $list->completed_at?->toIso8601String(),
            'items_count' => $this->whenCounted('items'),
            'unchecked_items_count' => $this->whenCounted('unchecked_items'),
            'items' => ShoppingListItemResource::collection($this->whenLoaded('items')),
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}
