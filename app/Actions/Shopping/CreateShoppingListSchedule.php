<?php

namespace App\Actions\Shopping;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Services\Shopping\ShoppingListScheduler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Creates a recurring shopping list and its first list, for the first run,
 * in the chosen workspace or the actor's default one (see
 * SavesShoppingListSchedule for the input).
 */
final readonly class CreateShoppingListSchedule
{
    use AuthorizesShoppingModule, SavesShoppingListSchedule;

    public function __construct(private SaveRecurrence $saveRecurrence, private ShoppingListScheduler $scheduler) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): ShoppingListSchedule
    {
        $this->authorizeShopping($actor);

        return $this->persist($this->saveRecurrence, $this->scheduler, $actor, $input, null);
    }
}
