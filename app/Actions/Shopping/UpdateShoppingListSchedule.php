<?php

namespace App\Actions\Shopping;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Services\Shopping\ShoppingListScheduler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Changes a recurring shopping list (see SavesShoppingListSchedule): the
 * whole form, optionally moving it to another workspace. Lists already
 * made keep their items.
 *
 * Any member of its workspace with the `shopping` module; anyone else gets
 * a 404.
 */
final readonly class UpdateShoppingListSchedule
{
    use AuthorizesShoppingModule, SavesShoppingListSchedule;

    public function __construct(private SaveRecurrence $saveRecurrence, private ShoppingListScheduler $scheduler) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ShoppingListSchedule $schedule, array $input): ShoppingListSchedule
    {
        $this->authorizeShoppingRecord($actor, $schedule);

        return $this->persist($this->saveRecurrence, $this->scheduler, $actor, $input, $schedule);
    }
}
