<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

/**
 * Only the super admin sees, sends, resends and revokes invitations (US-1.1).
 */
class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function resend(User $user, Invitation $invitation): bool
    {
        return $user->isSuperAdmin();
    }

    public function revoke(User $user, Invitation $invitation): bool
    {
        return $user->isSuperAdmin();
    }
}
