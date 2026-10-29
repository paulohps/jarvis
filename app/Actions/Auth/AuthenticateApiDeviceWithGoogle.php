<?php

namespace App\Actions\Auth;

use App\Exceptions\GoogleSignInRefused;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Signs the app in with the ID token of the native Google Sign-In (US-1.5):
 * the token is checked on Google (`GoogleIdTokenVerifier`), then the very
 * rules of the web Google sign-in apply (`AuthenticateWithGoogle`: linked
 * account, same e-mail, pending invitation, or "invitation required"), and
 * the device gets its own token. Any refusal is a validation error on
 * `id_token`.
 */
final readonly class AuthenticateApiDeviceWithGoogle
{
    public function __construct(
        private GoogleIdTokenVerifier $verifier,
        private AuthenticateWithGoogle $authenticateWithGoogle,
        private IssueDeviceToken $issueDeviceToken,
    ) {}

    /**
     * @param  array{id_token?: mixed, device_name?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(array $input): NewAccessToken
    {
        /** @var array{id_token: string, device_name: string} $validated */
        $validated = Validator::make($input, [
            'id_token' => ['required', 'string', 'max:4096'],
            ...IssueDeviceToken::deviceNameRules(),
        ])->validate();

        try {
            $user = ($this->authenticateWithGoogle)($this->verifier->verify($validated['id_token']));
        } catch (GoogleSignInRefused $refusal) {
            throw ValidationException::withMessages(['id_token' => $refusal->getMessage()]);
        }

        return ($this->issueDeviceToken)($user, $validated['device_name']);
    }
}
