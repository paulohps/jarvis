<?php

namespace App\Actions\Voice;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\Voice\FuzzyMatcher;
use App\Services\Voice\VoiceResolution;
use Illuminate\Database\Eloquent\Builder;

/**
 * The item a voice command checks (US-10.3): among the unchecked items of
 * the given list, or of every active list the actor sees when none was
 * said. A friendly id (an option picked after a clarification) is taken as
 * it is. Lists of workspaces the actor does not see are never searched.
 */
final readonly class ResolveShoppingListItem
{
    public function __construct(private FuzzyMatcher $matcher) {}

    public function __invoke(User $actor, string $spoken, ?ShoppingList $list): VoiceResolution
    {
        $items = ShoppingListItem::query()
            ->whereHas('list', fn (Builder $query) => $query->visibleTo($actor))
            ->whereNull('checked_at')
            ->when(
                $list !== null,
                fn (Builder $query) => $query->where('shopping_list_id', $list?->id),
                fn (Builder $query) => $query->whereHas('list', fn (Builder $query) => $query->whereNull('archived_at')),
            )
            ->with('list')
            ->orderBy('position')
            ->get();

        $picked = $items->firstWhere('friendly_id', $spoken);

        if ($picked !== null) {
            return VoiceResolution::resolved($picked);
        }

        $several = $list === null && $items->pluck('shopping_list_id')->unique()->count() > 1;

        return $this->matcher->resolve(
            $spoken,
            $items,
            fn (ShoppingListItem $item): string => $several ? "{$item->name} ({$item->list->name})" : $item->name,
            fn (ShoppingListItem $item): string => $item->friendly_id,
        );
    }
}
