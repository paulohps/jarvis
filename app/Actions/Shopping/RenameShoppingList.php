<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Renames a shopping list (US-8.1), archived or not: the same name rules as
 * CreateShoppingList. With a `workspace` (a friendly id, one of the actor's
 * workspaces with the `shopping` module) the list moves there — the name may
 * then be left out. A default list that moves stops being the default: the
 * other workspace may have its own.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class RenameShoppingList
{
    use AuthorizesShoppingModule, ValidatesShoppingListName;

    /**
     * @param  array<string, mixed>  $input  `name`, `workspace`
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingList $list, array $input): ShoppingList
    {
        $this->authorizeShoppingRecord($actor, $list);

        $moves = filled($input['workspace'] ?? null);
        $attributes = [];

        if (! $moves || array_key_exists('name', $input)) {
            $attributes['name'] = $this->validatedListName($input);
        }

        if ($moves) {
            $workspace = $this->workspaceFor($actor, $input, Module::SHOPPING);

            if ($workspace->id !== $list->workspace_id) {
                $attributes['workspace_id'] = $workspace->id;
                $attributes['is_default'] = false;
            }
        }

        $list->update($attributes);

        ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_RENAMED);

        return $list;
    }
}
