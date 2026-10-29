<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores when the signed-in user was last seen (US-2.2, "last access" in the
 * admin's user list), at most once a minute per user, without touching
 * `updated_at`.
 *
 * Runs in the `web` and `api` groups, after the request, so the API user of
 * `auth:sanctum` is known by then.
 */
class TrackLastSeen
{
    public const int INTERVAL_SECONDS = 60;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User && $this->isDue($user)) {
            $seenAt = now();

            User::query()->whereKey($user->id)->toBase()->update(['last_seen_at' => $seenAt]);

            $user->forceFill(['last_seen_at' => $seenAt])->syncOriginalAttribute('last_seen_at');
        }

        return $response;
    }

    private function isDue(User $user): bool
    {
        return $user->last_seen_at === null
            || $user->last_seen_at->lte(now()->subSeconds(self::INTERVAL_SECONDS));
    }
}
