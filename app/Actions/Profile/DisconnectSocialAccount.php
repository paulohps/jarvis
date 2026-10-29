<?php

namespace App\Actions\Profile;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The user unlinks a social account (US-3.1) from "Connected accounts". It
 * is only allowed once the user has a password, so they never lose every
 * way of signing in; a Google-only user sets a password first.
 */
final readonly class DisconnectSocialAccount
{
    /**
     * @throws AuthorizationException when the account belongs to someone else
     * @throws ValidationException when the user has no password
     */
    public function __invoke(User $user, SocialAccount $account): void
    {
        if ($account->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        if ($user->password === null) {
            throw ValidationException::withMessages([
                'social_account' => __('Set a password first.'),
            ]);
        }

        $account->delete();
    }
}
