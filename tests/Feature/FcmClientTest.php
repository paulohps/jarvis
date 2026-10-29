<?php

use App\Exceptions\FcmException;
use App\Services\Push\FcmClient;
use App\Services\Push\PushMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Push\FakeFcm;

beforeEach(function () {
    FakeFcm::install();
});

/**
 * The claims of a JWT, after checking its RS256 signature with the key of
 * the fake service account.
 *
 * @return array<string, mixed>
 */
function verifiedJwtClaims(string $jwt): array
{
    [$header, $claims, $signature] = explode('.', $jwt);
    $decode = fn (string $segment): string => base64_decode(strtr($segment, '-_', '+/'));

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(openssl_verify("{$header}.{$claims}", $decode($signature), FakeFcm::publicKey(), OPENSSL_ALGO_SHA256))->toBe(1);

    return json_decode($decode($claims), true);
}

test('fcm message is sent with the service account access token', function () {
    $name = app(FcmClient::class)->send('device-token', new PushMessage('Reminder', 'Take the medicine', 'https://jarvis.example/reminders'));

    expect($name)->toStartWith('projects/'.FakeFcm::PROJECT_ID.'/messages/');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== FcmClient::DEFAULT_TOKEN_URI) {
            return false;
        }

        $claims = verifiedJwtClaims($request['assertion']);

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $claims['iss'] === FakeFcm::CLIENT_EMAIL
            && $claims['scope'] === FcmClient::SCOPE
            && $claims['aud'] === FcmClient::DEFAULT_TOKEN_URI
            && $claims['exp'] - $claims['iat'] === 3600;
    });

    Http::assertSent(fn (Request $request): bool => FakeFcm::isSend($request)
        && $request->hasHeader('Authorization', 'Bearer '.FakeFcm::ACCESS_TOKEN)
        && $request['message'] === [
            'token' => 'device-token',
            'notification' => ['title' => 'Reminder', 'body' => 'Take the medicine'],
            'data' => ['deep_link' => 'https://jarvis.example/reminders'],
            // What a browser shows (the PWA); FCM ignores it for a phone.
            'webpush' => [
                'headers' => ['Urgency' => 'high'],
                'notification' => ['icon' => asset('icon-192.png'), 'badge' => asset('badge-96.png')],
                'fcm_options' => ['link' => 'https://jarvis.example/reminders'],
            ],
        ]);
});

test('access token is cached until it expires', function () {
    $client = app(FcmClient::class);
    $tokenRequests = fn (): int => Http::recorded(fn (Request $request): bool => $request->url() === FcmClient::DEFAULT_TOKEN_URI)->count();

    $client->send('first', new PushMessage('A', 'a'));
    $client->send('second', new PushMessage('B', 'b'));

    expect($tokenRequests())->toBe(1);

    // FCM hands out tokens for 3599 seconds; a minute before, a new one is asked for.
    $this->travel(3538)->seconds();
    $client->send('third', new PushMessage('C', 'c'));
    expect($tokenRequests())->toBe(1);

    $this->travel(2)->seconds();
    $client->send('fourth', new PushMessage('D', 'd'));
    expect($tokenRequests())->toBe(2);
});

test('credentials may be the path to the service account file', function () {
    $path = tempnam(sys_get_temp_dir(), 'firebase');
    file_put_contents($path, json_encode(FakeFcm::serviceAccount()));
    config(['services.firebase.credentials' => $path]);

    try {
        app(FcmClient::class)->send('device-token', new PushMessage('Hi', 'there'));
    } finally {
        unlink($path);
    }

    Http::assertSent(fn (Request $request): bool => FakeFcm::isSend($request));
});

test('fcm without credentials is not configured', function () {
    config(['services.firebase.credentials' => null]);
    $client = app(FcmClient::class);

    expect($client->isConfigured())->toBeFalse()
        ->and(fn () => $client->send('device-token', new PushMessage('Hi', 'there')))->toThrow(FcmException::class, 'FCM is not configured');

    Http::assertNothingSent();
});

test('fcm errors tell a rejected token from other failures', function (string $errorCode, bool $isTokenRejected) {
    FakeFcm::install(['device-token' => $errorCode]);

    $exception = rescue(fn () => app(FcmClient::class)->send('device-token', new PushMessage('Hi', 'there')), fn (Throwable $exception) => $exception, report: false);

    expect($exception)->toBeInstanceOf(FcmException::class)
        ->and($exception->errorCode)->toBe($errorCode)
        ->and($exception->isTokenRejected())->toBe($isTokenRejected);
})->with([
    'unregistered' => ['UNREGISTERED', true],
    'invalid argument' => ['INVALID_ARGUMENT', true],
    'unavailable' => ['UNAVAILABLE', false],
    'sender mismatch' => ['SENDER_ID_MISMATCH', false],
]);

test('invalid argument about another field keeps the token', function () {
    $exception = new FcmException('bad payload', 400, 'INVALID_ARGUMENT', ['message.data[0].value']);

    expect($exception->isTokenRejected())->toBeFalse()
        ->and((new FcmException('bad token', 400, 'INVALID_ARGUMENT', ['message.token']))->isTokenRejected())->toBeTrue();
});
