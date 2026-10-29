<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Archives a shopping list (US-8.1): it leaves the main screen and stays,
 * with its items, under "Archived", read only. Archiving it again keeps the
 * first date. An archived list stops being the default.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class ArchiveShoppingList
{
    use AuthorizesShoppingModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ShoppingList $list): ShoppingList
    {
        $this->authorizeShoppingRecord($actor, $list);

        if ($list->archived_at === null) {
            $list->update(['archived_at' => now(), 'is_default' => false]);

            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_ARCHIVED);
        }

        return $list;
    }
}
