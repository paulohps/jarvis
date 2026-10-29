<?php

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Services\Agenda\Google\GoogleCalendarClient;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Sends the user to Google to let Jarvis read their calendars, read only
 * (GoogleCalendarClient::SCOPES), with offline access and the consent
 * screen every time, so Google returns a refresh token.
 *
 * The callback URL (`reminders.google.callback`) must be among the
 * authorized redirect URIs of the Google OAuth client, and the Google
 * Calendar API enabled in its project.
 */
class GoogleCalendarConnectRedirectController extends Controller
{
    public const string SESSION_KEY = 'agenda.google_connect';

    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, true);

        /** @var GoogleProvider $google */
        $google = Socialite::driver('google');

        return $google
            ->redirectUrl(route('reminders.google.callback'))
            ->scopes(GoogleCalendarClient::SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'])
            ->redirect();
    }
}
