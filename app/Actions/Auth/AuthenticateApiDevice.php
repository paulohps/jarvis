<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Signs the app in with e-mail and password (US-1.5): the credentials go
 * through the very rules of the web sign-in (`AuthenticateUser`: the same
 * generic refusal for unknown, wrong, deactivated or deleted accounts, and
 * the same lockout per e-mail and IP), and the device gets its own token.
 */
final readonly class AuthenticateApiDevice
{
    public function __construct(
        private AuthenticateUser $authenticateUser,
        private IssueDeviceToken $issueDeviceToken,
    ) {}

    /**
     * @param  array{email?: mixed, password?: mixed, device_name?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(array $input, string $ipAddress): NewAccessToken
    {
        /** @var array{email: string, password: string, device_name: string} $validated */
        $validated = Validator::make($input, [
            ...AuthenticateUser::credentialRules(),
            ...IssueDeviceToken::deviceNameRules(),
        ])->validate();

        $user = $this->authenticateUser->verify(
            ['email' => $validated['email'], 'password' => $validated['password']],
            $ipAddress,
        );

        return ($this->issueDeviceToken)($user, $validated['device_name']);
    }
}
