<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In production Traefik terminates TLS and forwards over http; trust its
        // X-Forwarded-* headers so generated URLs keep https and the real host.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            EnsureUserIsActive::class,
            SetLocale::class,
            TrackLastSeen::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
            TrackLastSeen::class,
        ]);

        $middleware->alias([
            'module' => EnsureModuleAccess::class,
        ]);

        // 60 requests per minute per user or IP (AppServiceProvider).
        $middleware->throttleApi();

        // An API guest gets a 401 JSON answer, never a redirect to a sign-in page.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));

        // A signed-in user opening a visitor screen goes to the dashboard.
        $middleware->redirectUsersTo(fn (): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error under /api answers JSON (`message`, plus `errors` on a
        // 422), whatever the client accepts.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A missing record answers like any unknown page: the model and ids
        // looked up never reach the response.
        $exceptions->map(
            ModelNotFoundException::class,
            fn (ModelNotFoundException $exception) => new NotFoundHttpException(__('Not found.'), $exception),
        );
    })->create();
