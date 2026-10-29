<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reorders the items of a list by drag and drop: `$itemIds` are the
 * friendly ids in the new order, and they take the first positions. The
 * items left out — added on another device while this one was dragging —
 * keep their relative order after them, and ids that are not items of the
 * list (removed meanwhile) are ignored, so a stale screen cannot lose an
 * item nor touch another list.
 *
 * Checked items keep sinking to the end, in the order they were checked,
 * whatever their position: the order only shows among the unchecked ones.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class ReorderShoppingListItems
{
    use AuthorizesShoppingModule, SavesShoppingListItem;

    /**
     * @param  mixed  $itemIds  the friendly ids of the items, in the new order
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingList $list, mixed $itemIds): ShoppingList
    {
        $this->authorizeShoppingRecord($actor, $list);
        $this->ensureEditable($list);

        /** @var array{items: list<string>} $validated */
        $validated = Validator::make(['items' => $itemIds], [
            'items' => ['required', 'array', 'list'],
            'items.*' => ['required', 'string', 'distinct'],
        ], attributes: [
            'items' => __('items'),
        ])->validate();

        $changed = DB::transaction(function () use ($list, $validated): bool {
            ShoppingList::query()->whereKey($list->id)->lockForUpdate()->first();

            $items = ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'friendly_id', 'position']);

            $requested = array_flip($validated['items']);
            $ordered = $items
                ->filter(fn (ShoppingListItem $item): bool => isset($requested[$item->friendly_id]))
                ->sortBy(fn (ShoppingListItem $item): int => $requested[$item->friendly_id])
                ->concat($items->reject(fn (ShoppingListItem $item): bool => isset($requested[$item->friendly_id])))
                ->values();

            $changed = false;

            foreach ($ordered as $position => $item) {
                if ($item->position !== $position) {
                    ShoppingListItem::query()->whereKey($item->id)->update(['position' => $position]);
                    $changed = true;
                }
            }

            return $changed;
        });

        if ($changed) {
            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::ITEMS_REORDERED);
        }

        return $list;
    }
}
