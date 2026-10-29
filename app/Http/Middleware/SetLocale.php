<?php

namespace App\Http\Middleware;

use App\Support\Localization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the reader's language to the request (US-3.2, US-12.2): the
 * authenticated user's locale, otherwise `Accept-Language` on the API,
 * otherwise English. Carbon follows the application locale, so month and
 * day names come out in the same language.
 *
 * Runs in the `web` and `api` groups and as a Livewire persistent middleware.
 */
class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Localization::forRequest($request, $this->userOf($request)));

        $response = $next($request);

        // The same URL answers in more than one language.
        $response->setVary('Accept-Language', false);

        return $response;
    }

    /**
     * The group middleware runs before `auth:sanctum`, so an API request reads
     * its token user through the Sanctum guard directly.
     */
    private function userOf(Request $request): mixed
    {
        return $request->user() ?? ($request->is('api/*') ? $request->user('sanctum') : null);
    }
}
