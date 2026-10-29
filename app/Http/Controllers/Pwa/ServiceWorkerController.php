<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Support\Pwa\WebManifest;
use Illuminate\Http\Response;

/**
 * `GET /sw.js`: the service worker of the web app, rendered from a view so
 * it carries the cache version of the current build, the URL of the offline
 * page and the icons of a push, in the reader's language. Served from the
 * root so its scope is the whole app, and never cached by the browser, so
 * a deploy reaches it on the next visit.
 */
class ServiceWorkerController extends Controller
{
    public function __invoke(): Response
    {
        return response()
            ->view('pwa.service-worker', [
                'version' => WebManifest::version(),
                'offlineUrl' => route('pwa.offline', absolute: false),
                'startUrl' => '/',
                'icon' => asset('icon-192.png'),
                'badge' => asset('badge-96.png'),
                'appName' => (string) config('app.name'),
            ])
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
