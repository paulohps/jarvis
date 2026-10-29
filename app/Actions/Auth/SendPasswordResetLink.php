<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-mails a password reset link through the password broker (US-1.4). The
 * link is valid for 60 minutes (`auth.passwords.users.expire`).
 *
 * The answer is the same whatever happened — link sent, unknown address or a
 * link already sent in the last minute — so the form never tells whether an
 * address has an account. The broker also takes the same time for each.
 */
final readonly class SendPasswordResetLink
{
    /**
     * @param  array{email?: mixed}  $input
     * @return string the message to show
     *
     * @throws ValidationException when the e-mail is not an e-mail
     */
    public function __invoke(array $input): string
    {
        /** @var array{email: string} $validated */
        $validated = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validate();

        Password::broker()->sendResetLink(['email' => Str::lower(trim($validated['email']))]);

        return __(Password::RESET_LINK_SENT);
    }
}
