<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the web session of a user deactivated meanwhile (US-2.2): they are
 * signed out on their next request and sent to the sign-in page (a 401 for
 * a JSON request). `DeactivateUser` already deletes stored sessions; this
 * covers session drivers it cannot reach and sessions opened before.
 *
 * Runs in the `web` group.
 */
class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && $user->isDeactivated()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => __('Unauthenticated.')], 401)
                : redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
