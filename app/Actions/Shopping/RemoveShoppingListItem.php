<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Removes an item from its shopping list (US-8.1).
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class RemoveShoppingListItem
{
    use AuthorizesShoppingModule, SavesShoppingListItem;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingListItem $item): void
    {
        $list = $this->authorizeShoppingItem($actor, $item);
        $this->ensureEditable($list);

        $item->delete();

        ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::ITEM_REMOVED, $item->friendly_id);
    }
}
