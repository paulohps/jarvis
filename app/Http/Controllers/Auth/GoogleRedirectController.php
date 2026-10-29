<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * "Continue with Google" (US-1.3): sends the visitor to Google. From an
 * invitation link, the token is kept in the session so the callback accepts
 * that invitation and only with the invited e-mail.
 */
class GoogleRedirectController extends Controller
{
    public const string INVITATION_PARAMETER = 'invitation';

    public const string INVITATION_SESSION_KEY = 'google_invitation_token';

    public function __invoke(Request $request): RedirectResponse
    {
        $token = $request->query(self::INVITATION_PARAMETER);

        if (is_string($token) && $token !== '') {
            $request->session()->put(self::INVITATION_SESSION_KEY, $token);
        } else {
            $request->session()->forget(self::INVITATION_SESSION_KEY);
        }

        return Socialite::driver('google')->redirect();
    }
}
