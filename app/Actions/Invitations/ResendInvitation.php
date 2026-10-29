<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sends a pending invitation again (US-1.1) with a new token: the hash is
 * replaced, so the link of the earlier e-mail stops working, and the 7 days
 * start over.
 */
final readonly class ResendInvitation
{
    public function __construct(private SendInvitation $sendInvitation) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException when the invitation is no longer pending
     */
    public function __invoke(User $actor, Invitation $invitation): Invitation
    {
        Gate::forUser($actor)->authorize('resend', $invitation);

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('Only a pending invitation can be resent.'),
            ]);
        }

        $token = Invitation::newToken();

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(Invitation::VALIDITY_DAYS),
        ])->save();

        ($this->sendInvitation)($invitation, $token);

        return $invitation;
    }
}
