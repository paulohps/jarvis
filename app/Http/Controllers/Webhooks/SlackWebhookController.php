<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSlackEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * `POST /api/v1/webhooks/slack`: the Events API of the Slack app, behind
 * its signature (VerifySlackSignature). Answers the `url_verification`
 * challenge when the URL is set on the app, and queues every
 * `event_callback` (ProcessSlackEvent) at once — Slack wants its 200
 * within three seconds and retries otherwise. A retry of an event already
 * taken (same `event_id`, within five minutes) is dropped.
 */
class SlackWebhookController extends Controller
{
    private const int DEDUPE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return match ($payload['type'] ?? null) {
            'url_verification' => response()->json(['challenge' => (string) ($payload['challenge'] ?? '')]),
            'event_callback' => $this->queue($payload),
            default => response()->json(['status' => 'ignored']),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function queue(array $payload): JsonResponse
    {
        $eventId = $payload['event_id'] ?? null;

        if (is_string($eventId) && $eventId !== '' && ! Cache::add("slack:event:{$eventId}", true, self::DEDUPE_SECONDS)) {
            return response()->json(['status' => 'duplicate']);
        }

        ProcessSlackEvent::dispatch($payload);

        return response()->json(['status' => 'queued']);
    }
}
