<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Brings an archived shopping list back to the main screen (US-8.1),
 * open again when it had been completed.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class UnarchiveShoppingList
{
    use AuthorizesShoppingModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ShoppingList $list): ShoppingList
    {
        $this->authorizeShoppingRecord($actor, $list);

        if ($list->archived_at !== null) {
            $list->update(['archived_at' => null, 'completed_at' => null, 'completed_by_id' => null]);

            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_UNARCHIVED);
        }

        return $list;
    }
}
