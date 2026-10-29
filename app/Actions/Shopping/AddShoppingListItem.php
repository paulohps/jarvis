<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds an item to a shopping list (US-8.1): a name and an optional quantity
 * or note (see SavesShoppingListItem). It goes to the end of the list: its
 * `position` follows the highest one, taken with the list row locked so two
 * items added at once never share it.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class AddShoppingListItem
{
    use AuthorizesShoppingModule, SavesShoppingListItem;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingList $list, array $input): ShoppingListItem
    {
        $this->authorizeShoppingRecord($actor, $list);
        $this->ensureEditable($list);

        $attributes = $this->validatedItem($input);

        $item = DB::transaction(function () use ($actor, $list, $attributes): ShoppingListItem {
            ShoppingList::query()->whereKey($list->id)->lockForUpdate()->first();

            $lastPosition = ShoppingListItem::query()->where('shopping_list_id', $list->id)->max('position');

            return ShoppingListItem::query()->create([
                ...$attributes,
                'shopping_list_id' => $list->id,
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
                'created_by_id' => $actor->id,
            ]);
        });

        ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::ITEM_ADDED, $item->friendly_id);

        return $item;
    }
}
