<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Support\Pwa\WebManifest;
use Illuminate\Http\JsonResponse;

/**
 * `GET /manifest.webmanifest`: the web app manifest, in the reader's
 * language, cached by the browser for an hour (per session, since the
 * language comes with it).
 */
class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(WebManifest::toArray(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
