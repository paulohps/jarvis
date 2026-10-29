<?php

namespace App\Http\Resources;

use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item of a shopping list (US-8.1, US-8.2): name, quantity or note, its
 * place and whether it is checked — when and by whom. `client_updated_at` is
 * the moment of the last toggle that won (last write wins), for the app to
 * settle its offline marks.
 *
 * @mixin ShoppingListItem
 */
class ShoppingListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShoppingListItem $item */
        $item = $this->resource;

        return [
            'friendly_id' => $item->friendly_id,
            'list_id' => $this->whenLoaded('list', fn (): string => $item->list->friendly_id),
            'name' => $item->name,
            'quantity_note' => $item->quantity_note,
            'position' => $item->position,
            'is_checked' => $item->isChecked(),
            'checked_at' => $item->checked_at?->toIso8601String(),
            'checked_by' => $this->whenLoaded('checkedBy', fn (): ?array => $item->checkedBy === null ? null : [
                'friendly_id' => $item->checkedBy->friendly_id,
                'name' => $item->checkedBy->name,
            ]),
            'client_updated_at' => $item->client_updated_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
