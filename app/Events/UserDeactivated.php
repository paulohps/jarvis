<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The super admin deactivated a user (US-2.2), after their sessions and API
 * tokens were revoked. PauseWhatsappSchedulesOfDeactivatedUser pauses the user's WhatsApp
 * recurrences, which send through the user's own accounts.
 */
final class UserDeactivated
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
