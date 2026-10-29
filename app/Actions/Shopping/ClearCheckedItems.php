<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * "Clear checked" (US-8.2): removes every checked item of the list and
 * keeps the unchecked ones. Returns how many were removed; the event is
 * broadcast only when something was.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class ClearCheckedItems
{
    use AuthorizesShoppingModule, SavesShoppingListItem;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingList $list): int
    {
        $this->authorizeShoppingRecord($actor, $list);
        $this->ensureEditable($list);

        $removed = ShoppingListItem::query()
            ->where('shopping_list_id', $list->id)
            ->whereNotNull('checked_at')
            ->delete();

        if ($removed > 0) {
            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::CHECKED_CLEARED);
        }

        return $removed;
    }
}
