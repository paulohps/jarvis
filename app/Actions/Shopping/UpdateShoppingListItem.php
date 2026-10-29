<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Edits the name and the quantity or note of an item (US-8.1), keeping its
 * place and its checked state.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class UpdateShoppingListItem
{
    use AuthorizesShoppingModule, SavesShoppingListItem;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingListItem $item, array $input): ShoppingListItem
    {
        $list = $this->authorizeShoppingItem($actor, $item);
        $this->ensureEditable($list);

        $item->update($this->validatedItem($input));

        ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::ITEM_UPDATED, $item->friendly_id);

        return $item;
    }
}
