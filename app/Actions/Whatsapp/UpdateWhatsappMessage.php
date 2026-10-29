<?php

namespace App\Actions\Whatsapp;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edits a scheduled WhatsApp message (US-7.3) with the same rules as
 * ScheduleWhatsappMessage; the targets not listed any more are dropped and
 * its schedule is replaced. A paused message stays paused. A cancelled
 * message cannot be edited.
 *
 * Only its author, with the `whatsapp` module.
 */
final readonly class UpdateWhatsappMessage
{
    use AuthorizesWhatsappModule, SavesWhatsappMessage;

    public function __construct(private SaveRecurrence $saveRecurrence) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappMessage $message, array $input): WhatsappMessage
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('update', $message);

        if ($message->isCancelled()) {
            throw ValidationException::withMessages([
                'message' => __('A cancelled message cannot be edited.'),
            ]);
        }

        return $this->persist($this->saveRecurrence, $actor, $input, $message);
    }
}
