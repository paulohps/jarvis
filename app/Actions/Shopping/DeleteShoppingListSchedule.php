<?php

namespace App\Actions\Shopping;

use App\Actions\Recurrences\PauseRecurrence;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Stops a recurring shopping list with a soft delete, its recurrence
 * paused: no list is made any more. The lists it made stay as they are.
 *
 * Any member of its workspace with the `shopping` module; anyone else gets
 * a 404.
 */
final readonly class DeleteShoppingListSchedule
{
    use AuthorizesShoppingModule;

    public function __construct(private PauseRecurrence $pauseRecurrence) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ShoppingListSchedule $schedule): void
    {
        $this->authorizeShoppingRecord($actor, $schedule);

        DB::transaction(function () use ($schedule): void {
            if ($schedule->recurrence !== null) {
                ($this->pauseRecurrence)($schedule->recurrence);
            }

            $schedule->delete();
        });
    }
}
