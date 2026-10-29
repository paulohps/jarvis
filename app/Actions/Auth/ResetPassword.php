<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Sets a new password from a reset link (US-1.4). The broker refuses a token
 * older than 60 minutes and deletes it once used, so a link works only once.
 * An unknown e-mail gets the same answer as a bad token.
 */
final readonly class ResetPassword
{
    /**
     * @param  array{token?: mixed, email?: mixed, password?: mixed, password_confirmation?: mixed}  $input
     * @return string the message to show
     *
     * @throws ValidationException
     */
    public function __invoke(array $input): string
    {
        /** @var array{token: string, email: string, password: string} $validated */
        $validated = Validator::make($input, [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ])->validate();

        $status = Password::broker()->reset(
            [
                'token' => $validated['token'],
                'email' => Str::lower(trim($validated['email'])),
                'password' => $validated['password'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __(Password::INVALID_TOKEN),
            ]);
        }

        return __(Password::PASSWORD_RESET);
    }
}
