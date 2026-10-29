<?php

namespace App\Actions\Shopping;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * The shopping Actions are for the users with the `shopping` module
 * (US-2.1): the same check `module:shopping` makes on the routes, repeated
 * in the Action so the web and the API cannot drift apart.
 *
 * A list, schedule or item is touched only by who sees it: a member of its
 * workspace with the module (an item through its list). Any other is not
 * found (404), as if it did not exist.
 */
trait AuthorizesShoppingModule
{
    use ResolvesWorkspace;

    /**
     * @throws AuthorizationException
     */
    private function authorizeShopping(User $actor): void
    {
        Gate::forUser($actor)->authorize('access-module', Module::SHOPPING);
    }

    /**
     * The module, and that the actor sees the list or schedule.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    private function authorizeShoppingRecord(User $actor, ShoppingList|ShoppingListSchedule $record): void
    {
        $this->authorizeShopping($actor);
        $this->authorizeWorkspaceRecord($actor, $record);
    }

    /**
     * The module, and that the actor sees the item's list; the list, even
     * deleted, is returned.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    private function authorizeShoppingItem(User $actor, ShoppingListItem $item): ShoppingList
    {
        $this->authorizeShopping($actor);

        $list = $item->list()->withTrashed()->firstOrFail();

        if (! $list->isVisibleTo($actor)) {
            throw (new ModelNotFoundException)->setModel(ShoppingListItem::class, [$item->getKey()]);
        }

        return $list;
    }
}
