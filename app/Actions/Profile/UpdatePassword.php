<?php

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * The user changes their password (US-3.1). It takes the current password;
 * a user who only signs in with Google has none, so they set the first one
 * without it, and from then on may also sign in with e-mail and password.
 */
final readonly class UpdatePassword
{
    /**
     * @param  array{current_password?: mixed, password?: mixed, password_confirmation?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, array $input): User
    {
        $hasPassword = $user->password !== null;

        /** @var array{current_password?: string, password: string} $validated */
        $validated = Validator::make($input, [
            'current_password' => $hasPassword ? ['required', 'string'] : ['nullable'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ])->validate();

        if ($hasPassword && ! Hash::check($validated['current_password'] ?? '', (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('validation.current_password'),
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        return $user;
    }
}
