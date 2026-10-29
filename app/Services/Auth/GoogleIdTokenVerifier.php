<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleSignInRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\User as GoogleUser;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Checks the ID token of the apps' native Google Sign-In (US-1.5) on Google's
 * `tokeninfo` endpoint, which verifies the signature: the token must also be
 * issued by Google (`iss`), to one of our clients (`aud`: iOS, Android or
 * web) and not be expired (`exp`). The identity comes back as a Socialite
 * user, so `AuthenticateWithGoogle` applies the same rules as on the web.
 */
final readonly class GoogleIdTokenVerifier
{
    /**
     * The issuers Google signs its ID tokens with.
     *
     * @var list<string>
     */
    public const array ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @throws GoogleSignInRefused when the token is not valid for us, or Google cannot be reached
     */
    public function verify(string $idToken): GoogleUser
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get((string) config('services.google.tokeninfo_url'), ['id_token' => $idToken]);
        } catch (ConnectionException $exception) {
            report($exception);

            throw GoogleSignInRefused::unavailable();
        }

        if ($response->serverError()) {
            throw GoogleSignInRefused::unavailable();
        }

        /** @var array<string, mixed>|null $claims */
        $claims = $response->successful() ? $response->json() : null;

        if (! is_array($claims) || ! $this->isValid($claims)) {
            throw GoogleSignInRefused::invalidToken();
        }

        return (new SocialiteUser)
            ->setRaw($claims)
            ->map([
                'id' => (string) $claims['sub'],
                'name' => is_string($claims['name'] ?? null) ? $claims['name'] : null,
                'email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                'avatar' => is_string($claims['picture'] ?? null) ? $claims['picture'] : null,
            ]);
    }

    /**
     * The client ids an ID token may be issued to.
     *
     * @return list<string>
     */
    public static function audiences(): array
    {
        return array_values(array_filter([
            config('services.google.ios_client_id'),
            config('services.google.android_client_id'),
            config('services.google.client_id'),
        ], fn (mixed $clientId): bool => is_string($clientId) && $clientId !== ''));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function isValid(array $claims): bool
    {
        $subject = $claims['sub'] ?? null;
        $expiresAt = $claims['exp'] ?? null;

        return in_array($claims['aud'] ?? null, self::audiences(), true)
            && in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && is_numeric($expiresAt) && (int) $expiresAt > now()->getTimestamp()
            && (is_string($subject) || is_int($subject)) && (string) $subject !== '';
    }
}
