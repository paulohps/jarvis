<?php

namespace App\Actions\Whatsapp;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Schedules a WhatsApp message (US-7.3; Workflow 10): from one of the
 * user's own `open` accounts, to 1+ of its contacts and groups or numbers
 * typed in E.164 (created as unsynced targets), with a `{% %}` body, once at
 * a future date and time or on a repeating schedule. The scheduler then
 * sends it through `WhatsappMessage::runScheduledSlot()`.
 *
 * The input is the one of WhatsappMessageFields plus `recurrence` (see
 * SavesWhatsappMessage). Only users with the `whatsapp` module.
 */
final readonly class ScheduleWhatsappMessage
{
    use AuthorizesWhatsappModule, SavesWhatsappMessage;

    public function __construct(private SaveRecurrence $saveRecurrence) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): WhatsappMessage
    {
        $this->authorizeWhatsapp($actor);

        return $this->persist($this->saveRecurrence, $actor, $input, null);
    }
}
