<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Revokes a pending invitation (US-1.1): its link stops working and it stays
 * in the list as `revoked`.
 */
final readonly class RevokeInvitation
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException when the invitation is no longer pending
     */
    public function __invoke(User $actor, Invitation $invitation): Invitation
    {
        Gate::forUser($actor)->authorize('revoke', $invitation);

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('Only a pending invitation can be revoked.'),
            ]);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        return $invitation;
    }
}
