<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a Slack Events API request through only when it is signed with the
 * app's signing secret (`services.slack.signing_secret`), as Slack
 * documents (docs.slack.dev/authentication/verifying-requests-from-slack):
 * `X-Slack-Signature` is `v0=` and the HMAC-SHA256, with the secret, of
 * `v0:<X-Slack-Request-Timestamp>:<raw body>`, and the timestamp is at
 * most five minutes off, so a captured request cannot be replayed.
 * Anything else, or no secret configured, is a 401.
 */
class VerifySlackSignature
{
    public const string SIGNATURE_HEADER = 'X-Slack-Signature';

    public const string TIMESTAMP_HEADER = 'X-Slack-Request-Timestamp';

    public const int TOLERANCE_SECONDS = 300;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.slack.signing_secret');
        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER);
        $given = (string) $request->header(self::SIGNATURE_HEADER);

        abort_if($secret === '' || $timestamp === '' || $given === '', 401, __('Invalid webhook signature.'));
        abort_if(abs(now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS, 401, __('Invalid webhook signature.'));

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$request->getContent()}", $secret);

        abort_unless(hash_equals($expected, $given), 401, __('Invalid webhook signature.'));

        return $next($request);
    }
}
