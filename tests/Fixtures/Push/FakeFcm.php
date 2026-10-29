<?php

namespace Tests\Fixtures\Push;

use App\Services\Push\FcmClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fakes Firebase Cloud Messaging over `Http::fake()`, so no test reaches the
 * network: a service account with a throwaway RSA key is configured, the
 * OAuth endpoint hands out `ACCESS_TOKEN` and `messages:send` answers like
 * FCM v1 does, and any other request throws (`preventStrayRequests`).
 *
 * Pass `$errors` by registration token — an FCM error code such as
 * `UNREGISTERED`, `INVALID_ARGUMENT` or `UNAVAILABLE` — to make FCM refuse
 * that token.
 */
final class FakeFcm
{
    public const string PROJECT_ID = 'jarvis-test';

    public const string CLIENT_EMAIL = 'push@jarvis-test.iam.gserviceaccount.com';

    public const string ACCESS_TOKEN = 'ya29.fake-access-token';

    /**
     * The HTTP status FCM answers each error code with.
     *
     * @var array<string, int>
     */
    private const array STATUSES = [
        'INVALID_ARGUMENT' => 400,
        'THIRD_PARTY_AUTH_ERROR' => 401,
        'SENDER_ID_MISMATCH' => 403,
        'UNREGISTERED' => 404,
        'QUOTA_EXCEEDED' => 429,
        'INTERNAL' => 500,
        'UNAVAILABLE' => 503,
    ];

    private static ?string $privateKey = null;

    /**
     * @param  array<string, string>  $errors  registration token => FCM error code
     */
    public static function install(array $errors = []): void
    {
        config([
            'services.firebase.credentials' => json_encode(self::serviceAccount()),
            'services.firebase.project_id' => null,
        ]);

        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake([
            FcmClient::DEFAULT_TOKEN_URI => Http::response([
                'access_token' => self::ACCESS_TOKEN,
                'expires_in' => 3599,
                'token_type' => 'Bearer',
            ]),
            sprintf(FcmClient::SEND_URL, self::PROJECT_ID) => function (Request $request) use ($errors): PromiseInterface {
                $token = (string) $request['message']['token'];

                return isset($errors[$token])
                    ? self::error($errors[$token])
                    : Http::response(['name' => 'projects/'.self::PROJECT_ID.'/messages/'.md5($token)]);
            },
        ]);
    }

    /**
     * The service account JSON as Firebase hands it out.
     *
     * @return array<string, string>
     */
    public static function serviceAccount(): array
    {
        return [
            'type' => 'service_account',
            'project_id' => self::PROJECT_ID,
            'private_key_id' => 'fake-key-id',
            'private_key' => self::privateKey(),
            'client_email' => self::CLIENT_EMAIL,
            'token_uri' => FcmClient::DEFAULT_TOKEN_URI,
        ];
    }

    /**
     * The public half of the throwaway key, to check the JWT signature.
     */
    public static function publicKey(): string
    {
        $key = openssl_pkey_get_private(self::privateKey());
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Could not read the fake FCM public key: '.openssl_error_string());
        }

        return $details['key'];
    }

    /**
     * Whether the request is a `messages:send` call.
     */
    public static function isSend(Request $request): bool
    {
        return $request->url() === sprintf(FcmClient::SEND_URL, self::PROJECT_ID);
    }

    private static function error(string $errorCode): PromiseInterface
    {
        $status = self::STATUSES[$errorCode] ?? 500;

        return Http::response([
            'error' => [
                'code' => $status,
                'message' => "Fake FCM error {$errorCode}",
                'status' => $errorCode === 'UNREGISTERED' ? 'NOT_FOUND' : $errorCode,
                'details' => [
                    ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode],
                ],
            ],
        ], $status);
    }

    private static function privateKey(): string
    {
        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            if ($key === false || ! openssl_pkey_export($key, $pem)) {
                throw new RuntimeException('Could not generate the fake FCM private key: '.openssl_error_string());
            }

            self::$privateKey = $pem;
        }

        return self::$privateKey;
    }
}
