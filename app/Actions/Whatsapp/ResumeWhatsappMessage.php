<?php

namespace App\Actions\Whatsapp;

use App\Actions\Recurrences\ResumeRecurrence;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Resumes a paused WhatsApp message (US-7.3): its next run is computed from
 * now, so the runs missed while it was paused are skipped. Resuming a
 * running message changes nothing.
 *
 * Only its author, with the `whatsapp` module.
 */
final readonly class ResumeWhatsappMessage
{
    use AuthorizesWhatsappModule;

    public function __construct(private ResumeRecurrence $resumeRecurrence) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappMessage $message): WhatsappMessage
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('update', $message);

        if ($message->recurrence !== null) {
            ($this->resumeRecurrence)($message->recurrence);
        }

        return $message;
    }
}
