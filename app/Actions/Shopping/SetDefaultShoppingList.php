<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a shopping list its workspace's default, or unsets it: the list a
 * voice command means when it names none (ResolveShoppingList). In one
 * transaction the previous default of the same workspace is switched off
 * and this one on, so a workspace never has more than one (the partial
 * unique index on `workspace_id` is the safety net); the defaults of other
 * workspaces stay. An archived list cannot be the default.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class SetDefaultShoppingList
{
    use AuthorizesShoppingModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException when an archived list is made the default
     */
    public function __invoke(User $actor, ShoppingList $list, bool $isDefault = true): ShoppingList
    {
        $this->authorizeShoppingRecord($actor, $list);

        if ($isDefault && $list->isArchived()) {
            throw ValidationException::withMessages([
                'list' => __('An archived list cannot be the default.'),
            ]);
        }

        $previousIds = DB::transaction(function () use ($list, $isDefault): array {
            $previous = ShoppingList::query()
                ->where('workspace_id', $list->workspace_id)
                ->where('is_default', true)
                ->whereKeyNot($list->id)
                ->lockForUpdate()
                ->get(['id', 'friendly_id']);

            if ($isDefault) {
                ShoppingList::query()->whereKey($previous->modelKeys())->update(['is_default' => false]);
            }

            // Written by query: the instance may hold a stale `is_default`.
            ShoppingList::query()->whereKey($list->id)->update(['is_default' => $isDefault]);

            return $isDefault ? $previous->pluck('friendly_id')->all() : [];
        });

        foreach ([$list->friendly_id, ...$previousIds] as $listId) {
            ShoppingListChanged::dispatch($listId, ShoppingListChanged::LIST_DEFAULT_CHANGED);
        }

        return $list->refresh();
    }
}
