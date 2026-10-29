<?php

namespace App\Http\Controllers\Agenda;

use App\Actions\Agenda\ConnectGoogleCalendarAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Agenda\Google\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Throwable;

/**
 * Where Google sends the user back after allowing (or not) Jarvis to read
 * their calendars: stores the account and goes back to the agenda with
 * what happened. Both scopes must have been granted — Google lets the user
 * untick them.
 */
class GoogleCalendarConnectCallbackController extends Controller
{
    public const string STATUS_KEY = 'agenda.status';

    public const string ERROR_KEY = 'agenda.error';

    public function __invoke(Request $request, ConnectGoogleCalendarAccount $connect): RedirectResponse
    {
        $pending = $request->session()->pull(GoogleCalendarConnectRedirectController::SESSION_KEY);

        if ($pending === null || $request->filled('error')) {
            return $this->refuse(__('The Google account was not connected.'));
        }

        try {
            /** @var GoogleProvider $google */
            $google = Socialite::driver('google');

            /** @var GoogleUser $googleUser */
            $googleUser = $google->redirectUrl(route('reminders.google.callback'))->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->refuse(__('Google could not be reached. Try again.'));
        }

        if (array_diff(GoogleCalendarClient::SCOPES, (array) $googleUser->approvedScopes) !== []) {
            return $this->refuse(__('Google did not allow reading the calendars. Connect again and allow Jarvis to see your calendars and their events.'));
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $account = $connect($user, (string) $googleUser->getEmail(), $googleUser->refreshToken);
        } catch (ValidationException $exception) {
            return $this->refuse((string) collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('reminders.index')
            ->with(self::STATUS_KEY, __('The calendars of :email are on the agenda.', ['email' => $account->email]));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('reminders.index')->with(self::ERROR_KEY, $message);
    }
}
