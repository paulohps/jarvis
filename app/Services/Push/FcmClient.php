<?php

namespace App\Services\Push;

use App\Exceptions\FcmException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Firebase Cloud Messaging HTTP v1, the only push provider for iOS, Android
 * and the browsers the web app registered (US-5.2), over `Http` with no SDK:
 *
 * - the service account (`services.firebase.credentials`, its JSON or the
 *   path to it) signs a JWT (RS256) exchanged at its `token_uri` for an
 *   OAuth access token, cached until shortly before it expires;
 * - `POST /v1/projects/{project}/messages:send` sends one message to one
 *   registration token.
 *
 * Every call times out after `services.firebase.timeout` seconds (10); a
 * status of 400 or above, or no answer at all, throws `FcmException`.
 * Tests fake it with `FakeFcm`.
 */
final readonly class FcmClient
{
    public const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const string SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    public const string DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * How long before it expires a cached access token is replaced.
     */
    private const int EXPIRY_MARGIN_SECONDS = 60;

    /**
     * How long a signed JWT is valid: Google accepts one hour at most.
     */
    private const int ASSERTION_LIFETIME_SECONDS = 3600;

    public function __construct(
        #[Config('services.firebase.credentials')] private ?string $credentials,
        #[Config('services.firebase.project_id')] private ?string $projectId = null,
        #[Config('services.firebase.timeout')] private int $timeout = 10,
    ) {}

    /**
     * Whether a service account is set, so pushes can be sent at all.
     */
    public function isConfigured(): bool
    {
        return filled($this->credentials);
    }

    /**
     * Sends the message to one registration token and returns the FCM
     * message name (`projects/…/messages/…`).
     *
     * @throws FcmException
     */
    public function send(string $token, PushMessage $message): string
    {
        $account = $this->serviceAccount();
        $projectId = $this->projectId ?: ($account['project_id'] ?? null);

        if (blank($projectId)) {
            throw FcmException::notConfigured('no project id');
        }

        $payload = [
            'token' => $token,
            'notification' => ['title' => $message->title, 'body' => $message->body],
        ];

        if ($message->data() !== []) {
            $payload['data'] = $message->data();
        }

        $androidNotification = array_filter(['tag' => $message->thread, 'channel_id' => $message->androidChannel], fn (?string $value): bool => $value !== null);

        if ($androidNotification !== []) {
            $payload['android'] = ['notification' => $androidNotification];
        }

        if ($message->thread !== null) {
            $payload['apns'] = ['payload' => ['aps' => ['thread-id' => $message->thread]]];
        }

        $payload['webpush'] = $this->webpush($message);

        $response = $this->call('send', fn (PendingRequest $request): Response => $request
            ->withToken($this->accessToken())
            ->asJson()
            ->post(sprintf(self::SEND_URL, rawurlencode((string) $projectId)), ['message' => $payload]));

        return (string) $response->json('name');
    }

    /**
     * What a browser (the PWA) shows for the message: the app icon, the
     * monochrome badge Android draws in the status bar, the thread as the
     * `tag` so the latest replaces the one before, and the deep link as the
     * page a tap opens — FCM takes an https link only, so any other (a local
     * install) stays in `data.deep_link`, which the service worker reads too.
     *
     * @return array<string, mixed>
     */
    private function webpush(PushMessage $message): array
    {
        $notification = array_filter([
            'icon' => asset('icon-192.png'),
            'badge' => asset('badge-96.png'),
            'tag' => $message->thread,
            'renotify' => $message->thread !== null ? true : null,
        ], fn (mixed $value): bool => $value !== null);

        $webpush = [
            'headers' => ['Urgency' => 'high'],
            'notification' => $notification,
        ];

        if ($message->deepLink !== null && str_starts_with($message->deepLink, 'https://')) {
            $webpush['fcm_options'] = ['link' => $message->deepLink];
        }

        return $webpush;
    }

    /**
     * The OAuth access token of the service account, from the cache until
     * it is about to expire.
     *
     * @throws FcmException
     */
    public function accessToken(): string
    {
        $account = $this->serviceAccount();
        $cacheKey = 'fcm:access-token:'.sha1($account['client_email']);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUri = $account['token_uri'] ?? self::DEFAULT_TOKEN_URI;

        $response = $this->call('authenticate', fn (PendingRequest $request): Response => $request
            ->asForm()
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($account, $tokenUri),
            ]));

        $accessToken = (string) $response->json('access_token');

        if ($accessToken === '') {
            throw FcmException::fromResponse('authenticate', $response);
        }

        $lifetime = (int) $response->json('expires_in', 3600) - self::EXPIRY_MARGIN_SECONDS;

        if ($lifetime > 0) {
            Cache::put($cacheKey, $accessToken, $lifetime);
        }

        return $accessToken;
    }

    /**
     * The JWT the service account signs to ask for an access token.
     *
     * @param  array{client_email: string, private_key: string}  $account
     *
     * @throws FcmException
     */
    private function assertion(array $account, string $tokenUri): string
    {
        $issuedAt = now()->getTimestamp();

        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::ASSERTION_LIFETIME_SECONDS,
            ])),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw FcmException::notConfigured('the private key of the service account cannot sign');
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * The service account: the JSON itself or the file it is in.
     *
     * @return array{client_email: string, private_key: string, project_id?: string, token_uri?: string}
     *
     * @throws FcmException
     */
    private function serviceAccount(): array
    {
        $credentials = mb_trim((string) $this->credentials);

        if ($credentials === '') {
            throw FcmException::notConfigured('FIREBASE_CREDENTIALS is empty');
        }

        if (! str_starts_with($credentials, '{')) {
            $credentials = is_readable($credentials) ? (string) file_get_contents($credentials) : '';
        }

        try {
            $account = json_decode($credentials, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FcmException::notConfigured('FIREBASE_CREDENTIALS is neither a service account JSON nor a readable file');
        }

        if (! is_array($account) || blank($account['client_email'] ?? null) || blank($account['private_key'] ?? null)) {
            throw FcmException::notConfigured('the service account has no client_email or private_key');
        }

        /** @var array{client_email: string, private_key: string, project_id?: string, token_uri?: string} $account */
        return $account;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws FcmException
     */
    private function call(string $action, callable $call): Response
    {
        $request = Http::acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->timeout);

        try {
            $response = $call($request);
        } catch (ConnectionException $exception) {
            throw FcmException::unreachable($action, $exception);
        }

        if ($response->status() >= 400) {
            throw FcmException::fromResponse($action, $response);
        }

        return $response;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
