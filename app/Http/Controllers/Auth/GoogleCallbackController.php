<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateWithGoogle;
use App\Exceptions\GoogleSignInRefused;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as GoogleUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Where Google sends the visitor back (US-1.3): signs in the account
 * `AuthenticateWithGoogle` finds, or goes back to the sign-in screen — or to
 * the invitation page the visitor came from — with the reason it was refused.
 */
class GoogleCallbackController extends Controller
{
    /**
     * The flashed message of a refused Google sign-in.
     */
    public const string ERROR_KEY = 'google_error';

    public function __invoke(Request $request, AuthenticateWithGoogle $authenticateWithGoogle): RedirectResponse
    {
        $invitationToken = $request->session()->pull(GoogleRedirectController::INVITATION_SESSION_KEY);
        $invitationToken = is_string($invitationToken) ? $invitationToken : null;

        try {
            $googleUser = $this->googleUser($request);
            $user = $authenticateWithGoogle($googleUser, $invitationToken);
        } catch (GoogleSignInRefused $refusal) {
            return $this->refuse($refusal->getMessage(), $invitationToken);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * The Google identity; cancelling on Google or a broken round trip
     * (expired state, network) is a refusal, not an error page.
     *
     * @throws GoogleSignInRefused
     */
    private function googleUser(Request $request): GoogleUser
    {
        if ($request->filled('error')) {
            throw GoogleSignInRefused::unavailable();
        }

        try {
            return Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            throw GoogleSignInRefused::unavailable();
        }
    }

    private function refuse(string $message, ?string $invitationToken): RedirectResponse
    {
        $target = $invitationToken === null
            ? route('login')
            : route('invitations.accept', ['token' => $invitationToken]);

        return redirect()->to($target)->with(self::ERROR_KEY, $message);
    }
}
