<?php

namespace App\Http\Middleware;

use App\Exceptions\ModuleAccessDenied;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:<slug>` (US-2.1, US-12.3): lets through only users with the module
 * switched on (the `access-module` gate; the super admin has every module).
 * Anyone else gets a 403 — the module error page on the web, JSON on the API.
 *
 * Runs after `auth` / `auth:sanctum`. Access is read from `module_user` on
 * every request, never cached, so switching a module off holds on the very
 * next request. Also a Livewire persistent middleware, so updates of a module
 * screen already open are refused too.
 */
class EnsureModuleAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws ModuleAccessDenied
     */
    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        $user = $request->user();

        if (! $user instanceof User || Gate::forUser($user)->denies('access-module', $moduleSlug)) {
            throw ModuleAccessDenied::for($moduleSlug);
        }

        return $next($request);
    }
}
