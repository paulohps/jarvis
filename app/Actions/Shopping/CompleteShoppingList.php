<?php

namespace App\Actions\Shopping;

use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Shopping\ShoppingListScheduler;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Completes a shopping list: it is archived as done (by whom, when) and,
 * when it is a recurring one, the next list is made at once. Completing it
 * again changes nothing.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class CompleteShoppingList
{
    use AuthorizesShoppingModule;

    public function __construct(private ShoppingListScheduler $scheduler) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ShoppingList $list): ShoppingList
    {
        $this->authorizeShoppingRecord($actor, $list);

        return $this->scheduler->complete($list, $actor);
    }
}
