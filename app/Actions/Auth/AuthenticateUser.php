<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signs a user in to the web session with e-mail and password (US-1.4), or
 * only checks the credentials for the API sign-in (`verify`, US-1.5), with
 * the same rules.
 *
 * Every refusal — unknown e-mail, wrong password, deactivated (US-2.2) or
 * deleted account — gives the same generic message, so the form never tells
 * whether an address has an account. Five failed attempts per minute for the
 * same e-mail and IP lock that pair out until the minute is over.
 */
final readonly class AuthenticateUser
{
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 60;

    /**
     * @param  array{email?: mixed, password?: mixed, remember?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(array $input, string $ipAddress): User
    {
        /** @var array{email: string, password: string, remember?: bool} $validated */
        $validated = Validator::make($input, [
            ...self::credentialRules(),
            'remember' => ['sometimes', 'boolean'],
        ])->validate();

        $guard = Auth::guard('web');

        $this->checkCredentials(
            $validated,
            $ipAddress,
            fn (array $credentials): bool => $guard->attempt($credentials, (bool) ($validated['remember'] ?? false)),
        );

        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        /** @var User $user */
        $user = $guard->user();

        return $user;
    }

    /**
     * Checks e-mail and password without signing in to any session, for the
     * API sign-in (US-1.5): the same refusals and the same lockout.
     *
     * @param  array{email: string, password: string}  $credentials  validated by `credentialRules()`
     *
     * @throws ValidationException
     */
    public function verify(array $credentials, string $ipAddress): User
    {
        $guard = Auth::guard('web');

        // `once` signs the user in for this request only: nothing is stored.
        $this->checkCredentials($credentials, $ipAddress, fn (array $attempt): bool => $guard->once($attempt));

        /** @var User $user */
        $user = $guard->user();

        return $user;
    }

    /**
     * The validation rules of the e-mail and password.
     *
     * @return array<string, list<string>>
     */
    public static function credentialRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Runs the attempt under the lockout, with deactivated and deleted users
     * left out; a refusal counts towards the lockout.
     *
     * @param  array{email: string, password: string}  $credentials
     * @param  callable(array<int|string, mixed>): bool  $attempt
     *
     * @throws ValidationException
     */
    private function checkCredentials(array $credentials, string $ipAddress, callable $attempt): void
    {
        $throttleKey = self::throttleKey($credentials['email'], $ipAddress);

        $this->ensureIsNotRateLimited($throttleKey);

        $isAuthenticated = $attempt([
            'email' => Str::lower($credentials['email']),
            'password' => $credentials['password'],
            // Deactivated users are not even looked up; deleted ones never are.
            fn (Builder $query) => $query->whereNull('deactivated_at'),
        ]);

        if (! $isAuthenticated) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
    }

    /**
     * The limiter key of an e-mail and IP pair.
     */
    public static function throttleKey(string $email, string $ipAddress): string
    {
        return 'login:'.Str::transliterate(Str::lower(trim($email))).'|'.$ipAddress;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }
}
