<?php

namespace App\Actions\Voice;

use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Voice\FuzzyMatcher;
use App\Services\Voice\VoiceResolution;

/**
 * The active shopping list a voice command means (US-10.3): "dessa semana"
 * finds "Compras da semana". A friendly id (an option picked after a
 * clarification) is taken as it is. Left unsaid, the actor's default list
 * is the one (ShoppingList::defaultFor: the default of their default
 * workspace); with no default, the only active list; with more, every list
 * is an option. Only the lists of the actor's workspaces with the
 * `shopping` module are candidates, never archived or deleted ones.
 */
final readonly class ResolveShoppingList
{
    public function __construct(private FuzzyMatcher $matcher) {}

    public function __invoke(User $actor, ?string $spoken): VoiceResolution
    {
        $lists = ShoppingList::query()->visibleTo($actor)->notArchived()->orderBy('name')->get();
        $label = fn (ShoppingList $list): string => $list->name;
        $id = fn (ShoppingList $list): string => $list->friendly_id;

        $picked = $spoken === null ? null : $lists->firstWhere('friendly_id', $spoken);

        if ($picked !== null) {
            return VoiceResolution::resolved($picked);
        }

        if ($spoken === null) {
            $default = ShoppingList::defaultFor($actor);

            if ($default !== null) {
                return VoiceResolution::resolved($default);
            }

            return match ($lists->count()) {
                0 => VoiceResolution::notFound(),
                1 => VoiceResolution::resolved($lists->sole()),
                default => VoiceResolution::ambiguous(FuzzyMatcher::options($lists, $label, $id)),
            };
        }

        return $this->matcher->resolve($spoken, $lists, $label, $id);
    }
}
