<?php

namespace App\Actions\Whatsapp;

use App\Actions\Recurrences\PauseRecurrence;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a scheduled WhatsApp message (US-7.3) with a soft delete. Its
 * schedule is paused too, so nothing runs for it even if it is restored.
 * The deliveries already made stay in the history.
 *
 * Only its author, with the `whatsapp` module.
 */
final readonly class DeleteWhatsappMessage
{
    use AuthorizesWhatsappModule;

    public function __construct(private PauseRecurrence $pauseRecurrence) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappMessage $message): void
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('delete', $message);

        DB::transaction(function () use ($message): void {
            if ($message->recurrence !== null) {
                ($this->pauseRecurrence)($message->recurrence);
            }

            $message->delete();
        });
    }
}
