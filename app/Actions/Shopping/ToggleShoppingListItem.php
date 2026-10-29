<?php

namespace App\Actions\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Checks or unchecks an item (US-8.2): a checked item records when and by
 * whom, and goes to the end of the list; unchecking clears both.
 *
 * Conflicts are settled by the last write (Workflow 11): every toggle stores
 * the moment it was made in `client_updated_at` — the device's clock for a
 * write made offline and synced later, now for one made online — and a
 * write older than the stored one is ignored. The check and the write are
 * one conditional UPDATE, so two writes at the same time cannot both win.
 * A device clock ahead of the server's counts as now, so it cannot lock the
 * item against every later write.
 *
 * Any member of the list's workspace with the `shopping` module; anyone
 * else gets a 404.
 */
final readonly class ToggleShoppingListItem
{
    use AuthorizesShoppingModule, SavesShoppingListItem;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingListItem $item, bool $checked, ?DateTimeInterface $clientUpdatedAt = null): ShoppingListItem
    {
        $item->refresh();
        $list = $this->authorizeShoppingItem($actor, $item);
        $this->ensureEditable($list);

        $now = CarbonImmutable::now();
        $writtenAt = $clientUpdatedAt === null ? $now : CarbonImmutable::instance($clientUpdatedAt)->min($now);

        $applied = ShoppingListItem::query()
            ->whereKey($item->id)
            ->where(fn ($query) => $query
                ->whereNull('client_updated_at')
                ->orWhere('client_updated_at', '<=', $writtenAt))
            ->update($checked
                ? [
                    'checked_at' => $item->checked_at ?? $writtenAt,
                    'checked_by_id' => $item->checked_by_id ?? $actor->id,
                    'client_updated_at' => $writtenAt,
                    'updated_at' => $now,
                ]
                : [
                    'checked_at' => null,
                    'checked_by_id' => null,
                    'client_updated_at' => $writtenAt,
                    'updated_at' => $now,
                ]) > 0;

        $item->refresh();

        if ($applied) {
            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::ITEM_TOGGLED, $item->friendly_id);
        }

        return $item;
    }
}
