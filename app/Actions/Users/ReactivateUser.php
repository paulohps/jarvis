<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The super admin reactivates a user (US-2.2): they can sign in again with
 * their password or Google and find their modules and data as they were.
 * Revoked API tokens do not come back; the apps sign in again.
 */
final readonly class ReactivateUser
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException when the user is not deactivated
     */
    public function __invoke(User $actor, User $user): User
    {
        Gate::forUser($actor)->authorize('super-admin');

        if (! $user->isDeactivated()) {
            throw ValidationException::withMessages([
                'user' => __('This user is already active.'),
            ]);
        }

        $user->forceFill(['deactivated_at' => null])->save();

        return $user;
    }
}
