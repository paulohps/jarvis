<?php

namespace App\Actions\Whatsapp;

use App\Actions\Recurrences\PauseRecurrence;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Pauses a scheduled WhatsApp message (US-7.3): nothing is sent until it is
 * resumed (ResumeWhatsappMessage). Pausing twice changes nothing.
 *
 * Only its author, with the `whatsapp` module.
 */
final readonly class PauseWhatsappMessage
{
    use AuthorizesWhatsappModule;

    public function __construct(private PauseRecurrence $pauseRecurrence) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappMessage $message): WhatsappMessage
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('update', $message);

        if ($message->recurrence !== null) {
            ($this->pauseRecurrence)($message->recurrence);
        }

        return $message;
    }
}
