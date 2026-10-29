<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Google sign-in was refused (US-1.3), with the message shown to the
 * visitor. Nothing was created.
 */
final class GoogleSignInRefused extends RuntimeException
{
    public static function invitationRequired(): self
    {
        return new self(__('Invitation required: ask the admin to invite this Google account e-mail.'));
    }

    public static function emailMismatch(): self
    {
        return new self(__('This Google account does not use the invited e-mail. Sign in to Google with the invited e-mail.'));
    }

    public static function deactivated(): self
    {
        return new self(__('This account is deactivated.'));
    }

    public static function unverifiedEmail(): self
    {
        return new self(__('This Google account has no verified e-mail.'));
    }

    public static function invalidInvitation(): self
    {
        return new self(__('This invitation is no longer valid'));
    }

    /**
     * The ID token sent by an app is not a valid Google token issued to one
     * of our clients (US-1.5).
     */
    public static function invalidToken(): self
    {
        return new self(__('This Google sign-in is not valid. Try again.'));
    }

    public static function unavailable(): self
    {
        return new self(__('Signing in with Google did not work. Try again.'));
    }
}
