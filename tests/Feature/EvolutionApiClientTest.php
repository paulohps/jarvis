<?php

use App\Exceptions\EvolutionApiException;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Services\Whatsapp\EvolutionQrCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    config([
        'services.evolution.url' => 'http://evolution.test',
        'services.evolution.key' => 'global-api-key',
        'services.evolution.webhook_url' => 'http://jarvis.test/api/v1/webhooks/evolution',
        'services.evolution.webhook_secret' => 'shared-secret',
    ]);
});

test('client raises typed exception on api errors', function () {
    FakeEvolutionApi::install([
        'connect' => Http::response(['status' => 404, 'error' => 'Not Found', 'response' => ['message' => ['The "ghost" instance does not exist']]], 404),
        'sendText' => Http::response(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => [['exists' => false, 'number' => '5511']]]], 400),
        'logout' => Http::response(['status' => 500, 'error' => 'Internal Server Error'], 500),
        'fetchGroups' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    $client = app(EvolutionApiClient::class);

    $notFound = rescue(fn () => $client->connect('ghost'), fn (Throwable $exception) => $exception, report: false);

    expect($notFound)->toBeInstanceOf(EvolutionApiException::class)
        ->and($notFound->status)->toBe(404)
        ->and($notFound->isNotFound())->toBeTrue()
        ->and($notFound->getMessage())->toContain('connect')->toContain('does not exist');

    expect(fn () => $client->sendText('jarvis', '5511', 'Hi'))->toThrow(EvolutionApiException::class, 'status 400')
        ->and(fn () => $client->logout('jarvis'))->toThrow(EvolutionApiException::class, 'status 500');

    // A timeout is the same typed exception, without a status.
    $timeout = rescue(fn () => $client->fetchGroups('jarvis'), fn (Throwable $exception) => $exception, report: false);

    expect($timeout)->toBeInstanceOf(EvolutionApiException::class)
        ->and($timeout->status)->toBeNull()
        ->and($timeout->getPrevious())->toBeInstanceOf(ConnectionException::class);
});

test('client sends api key header', function () {
    FakeEvolutionApi::install();

    $client = app(EvolutionApiClient::class);

    $qrCode = $client->createInstance('jarvis-abc');

    expect($qrCode)->toBeInstanceOf(EvolutionQrCode::class)
        ->and($qrCode->base64)->toBe(FakeEvolutionApi::CREATED_QR)
        ->and($client->connect('jarvis-abc')->base64)->toBe(FakeEvolutionApi::RENEWED_QR)
        ->and($client->fetchContacts('jarvis-abc'))->toBe([
            ['jid' => '5511911110000@s.whatsapp.net', 'name' => 'Ana', 'picture' => FakeEvolutionApi::PICTURE_URL],
            ['jid' => '5511922220000@s.whatsapp.net', 'name' => 'Bruno', 'picture' => null],
        ])
        ->and($client->fetchGroups('jarvis-abc'))->toBe([['jid' => '120363000000000001@g.us', 'name' => 'Family', 'picture' => null]])
        ->and($client->sendText('jarvis-abc', '5511911110000', 'Hi'))->toBe(FakeEvolutionApi::MESSAGE_ID);

    $client->logout('jarvis-abc');
    $client->deleteInstance('jarvis-abc');

    Http::assertSentCount(7);
    Http::assertSent(fn (Request $request): bool => $request->header('apikey') === ['global-api-key']
        && str_starts_with($request->url(), 'http://evolution.test/'));

    // Every call carries the key, not only the first one.
    expect(Http::recorded()->every(fn (array $pair): bool => $pair[0]->header('apikey') === ['global-api-key']))->toBeTrue();

    // The instance reports its QR codes, connection changes and messages and their receipts to us, with the shared secret, and asks for the full history.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/instance/create'
        && $request['instanceName'] === 'jarvis-abc'
        && $request['qrcode'] === true
        && $request['syncFullHistory'] === true
        && $request['webhook']['url'] === 'http://jarvis.test/api/v1/webhooks/evolution'
        && $request['webhook']['headers'] === [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret']
        && $request['webhook']['events'] === [
            'QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT', 'MESSAGES_SET', 'MESSAGES_UPDATE', 'MESSAGES_DELETE', 'MESSAGES_EDITED',
            'SEND_MESSAGE', 'SEND_MESSAGE_UPDATE', 'PRESENCE_UPDATE', 'GROUPS_UPSERT', 'GROUP_UPDATE', 'GROUP_PARTICIPANTS_UPDATE',
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://evolution.test/message/sendText/jarvis-abc'
        && $request->data() === ['number' => '5511911110000', 'text' => 'Hi']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://evolution.test/instance/delete/jarvis-abc');

    // Linking by QR code sends no number.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/instance/create' && ! isset($request['number']));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/instance/connect/jarvis-abc');
});

test('client asks for a pairing code with the number', function () {
    FakeEvolutionApi::install();

    $client = app(EvolutionApiClient::class);

    expect($client->createInstance('jarvis-abc', '5511999990000')->pairingCode)->toBe(FakeEvolutionApi::PAIRING_CODE);

    $client->connect('jarvis-abc', '5511999990000');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/instance/create'
        && $request['instanceName'] === 'jarvis-abc'
        && $request['number'] === '5511999990000');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://evolution.test/instance/connect/jarvis-abc?number=5511999990000');
});
