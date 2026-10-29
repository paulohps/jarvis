<?php

namespace App\Actions\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs the user out of the web session (US-1.4): the session is
 * invalidated, so nothing in it survives, and the CSRF token is regenerated,
 * so a form left open in another tab no longer posts.
 */
final readonly class LogoutUser
{
    public function __invoke(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
