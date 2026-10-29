<?php

namespace App\Listeners;

use App\Events\UserDeactivated;
use App\Models\Recurrence;
use App\Models\WhatsappMessage;

/**
 * A deactivated user's WhatsApp messages go out through the user's own
 * accounts (US-2.2), so their schedules are all paused — deleted messages
 * included, in case they are restored. Reactivating the user does not
 * resume them: the user resumes each one.
 */
final class PauseWhatsappSchedulesOfDeactivatedUser
{
    public function handle(UserDeactivated $event): void
    {
        Recurrence::query()
            ->where('schedulable_type', (new WhatsappMessage)->getMorphClass())
            ->whereIn('schedulable_id', WhatsappMessage::withTrashed()->where('user_id', $event->user->id)->select('id'))
            ->update(['is_paused' => true, 'updated_at' => now()]);
    }
}
