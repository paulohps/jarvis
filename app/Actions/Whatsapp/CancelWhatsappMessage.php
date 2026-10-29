<?php

namespace App\Actions\Whatsapp;

use App\Models\Recurrence;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a `once` WhatsApp message before its time (US-7.3): it gets
 * `cancelled_at` and its schedule loses its next run, so it is never sent.
 * A repeating message is paused instead (PauseRecurrence), and a message
 * already sent — or whose time came — cannot be cancelled. Cancelling twice
 * changes nothing.
 *
 * The schedule row is locked meanwhile, so the scheduler either sends the
 * message before or skips it after, never both.
 *
 * Only its author, with the `whatsapp` module.
 */
final readonly class CancelWhatsappMessage
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappMessage $message): WhatsappMessage
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('update', $message);

        if ($message->isCancelled()) {
            return $message;
        }

        if (! $message->isOnce()) {
            throw ValidationException::withMessages([
                'message' => __('Only a one-time message can be cancelled. Pause a recurring one instead.'),
            ]);
        }

        return DB::transaction(function () use ($message): WhatsappMessage {
            $schedule = Recurrence::query()->whereKey($message->recurrence?->id)->lockForUpdate()->first();

            if ($schedule?->next_run_at === null || ! $schedule->next_run_at->isFuture() || $message->deliveries()->exists()) {
                throw ValidationException::withMessages([
                    'message' => __('This message was already sent, so it cannot be cancelled.'),
                ]);
            }

            $schedule->update(['next_run_at' => null]);
            $message->update(['cancelled_at' => now()]);

            return $message->unsetRelation('recurrence');
        });
    }
}
