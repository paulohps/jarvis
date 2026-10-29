<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Removes a shopping list (US-8.1) with a soft delete: it leaves the main
 * screen and "Archived", and its items stay with it. A deleted list
 * stops being the default.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class DeleteShoppingList
{
    use AuthorizesShoppingModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ShoppingList $list): void
    {
        $this->authorizeShoppingRecord($actor, $list);

        $list->update(['is_default' => false]);
        $list->delete();

        ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_DELETED);
    }
}
