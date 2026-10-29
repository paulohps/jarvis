<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Creates a shopping list (US-8.1; Workflow 11): a name, required and up to
 * 100 characters, in the `workspace` of the input (a friendly id, one of the
 * actor's workspaces with the `shopping` module) or, left out, in their
 * default one (ResolvesWorkspace).
 *
 * The list is shared by the members of its workspace with the module.
 */
final readonly class CreateShoppingList
{
    use AuthorizesShoppingModule, ValidatesShoppingListName;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): ShoppingList
    {
        $this->authorizeShopping($actor);

        $name = $this->validatedListName($input);
        $workspace = $this->workspaceFor($actor, $input, Module::SHOPPING);

        $list = ShoppingList::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'created_by_id' => $actor->id,
        ]);

        ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_CREATED);

        return $list;
    }
}
