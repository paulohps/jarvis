<?php

namespace App\Http\Middleware;

use App\Services\Whatsapp\EvolutionApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an Evolution API webhook through only with the shared secret
 * (`services.evolution.webhook_secret`) in the `X-Evolution-Secret` header,
 * which `EvolutionApiClient::createInstance()` configures on every instance
 * (US-7.2). Anything else — no header, a wrong one, or no secret configured
 * at all — is a 401.
 */
class VerifyEvolutionWebhookSecret
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.evolution.webhook_secret');
        $given = (string) $request->header(EvolutionApiClient::WEBHOOK_SECRET_HEADER);

        abort_if($expected === '' || ! hash_equals($expected, $given), 401, __('Invalid webhook secret.'));

        return $next($request);
    }
}
