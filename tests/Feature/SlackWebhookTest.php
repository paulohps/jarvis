<?php

use App\Http\Middleware\VerifySlackSignature;
use App\Jobs\ProcessSlackEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));

    config(['services.slack.signing_secret' => 'slack-signing-secret']);

    $this->post = function (array $payload, ?string $secret = 'slack-signing-secret', ?int $timestamp = null) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', VerifySlackSignature::TIMESTAMP_HEADER))] = (string) $timestamp;
            $headers['HTTP_'.strtoupper(str_replace('-', '_', VerifySlackSignature::SIGNATURE_HEADER))] = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret);
        }

        return $this->call('POST', route('webhooks.slack'), [], [], [], $headers, $body);
    };
});

test('the slack webhook answers the url verification challenge', function () {
    expect(route('webhooks.slack', absolute: false))->toBe('/api/v1/webhooks/slack');

    ($this->post)(['type' => 'url_verification', 'challenge' => 'abc123', 'token' => 'x'])
        ->assertOk()
        ->assertExactJson(['challenge' => 'abc123']);
});

test('a request without a valid signature is refused', function () {
    Queue::fake();
    $payload = ['type' => 'event_callback', 'event_id' => 'Ev1', 'team_id' => 'T1', 'event' => ['type' => 'message']];

    ($this->post)($payload, null)->assertUnauthorized();
    ($this->post)($payload, 'wrong-secret')->assertUnauthorized();
    ($this->post)($payload, timestamp: now()->subMinutes(6)->getTimestamp())->assertUnauthorized();

    config(['services.slack.signing_secret' => null]);
    ($this->post)($payload, '')->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('an event callback is queued once, retries of the same event are dropped', function () {
    Queue::fake();
    $payload = ['type' => 'event_callback', 'event_id' => 'Ev0001', 'team_id' => 'T1', 'event' => ['type' => 'message', 'channel' => 'D1', 'text' => 'hi', 'ts' => '1.2']];

    ($this->post)($payload)->assertOk()->assertJson(['status' => 'queued']);
    ($this->post)($payload)->assertOk()->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(ProcessSlackEvent::class, 1);
    Queue::assertPushed(ProcessSlackEvent::class, fn (ProcessSlackEvent $job): bool => $job->payload['event_id'] === 'Ev0001' && $job->queue === 'chats');

    ($this->post)(['type' => 'something_else'])->assertOk()->assertJson(['status' => 'ignored']);
});
