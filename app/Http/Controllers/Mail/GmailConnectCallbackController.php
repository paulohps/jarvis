<?php

namespace App\Http\Controllers\Mail;

use App\Actions\Mail\ConnectGmailMailService;
use App\Http\Controllers\Controller;
use App\Models\MailService;
use App\Models\User;
use App\Services\Mail\GmailTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Throwable;

/**
 * Where Google sends the user back after allowing (or not) Jarvis to send
 * from their account: stores the service and goes back to the mail
 * services with what happened.
 */
class GmailConnectCallbackController extends Controller
{
    public const string STATUS_KEY = 'mail_services.status';

    public const string ERROR_KEY = 'mail_services.error';

    public function __invoke(Request $request, ConnectGmailMailService $connect): RedirectResponse
    {
        /** @var array{service?: string|null, name?: string|null, from_name?: string|null, is_global?: bool}|null $pending */
        $pending = $request->session()->pull(GmailConnectRedirectController::SESSION_KEY);

        if ($pending === null || $request->filled('error')) {
            return $this->refuse(__('The Google account was not connected.'));
        }

        try {
            /** @var GoogleProvider $google */
            $google = Socialite::driver('google');

            /** @var GoogleUser $googleUser */
            $googleUser = $google->redirectUrl(route('mail-services.google.callback'))->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->refuse(__('Google could not be reached. Try again.'));
        }

        if (! in_array(GmailTransport::SCOPE, (array) $googleUser->approvedScopes, true)) {
            return $this->refuse(__('Google did not allow sending. Connect again and allow Jarvis to send e-mail on your behalf.'));
        }

        /** @var User $user */
        $user = $request->user();
        $service = filled($pending['service'] ?? null)
            ? MailService::query()->where('friendly_id', $pending['service'])->firstOrFail()
            : null;

        try {
            $service = $connect($user, (string) $googleUser->getEmail(), $googleUser->refreshToken, $pending, $service);
        } catch (ValidationException $exception) {
            return $this->refuse((string) collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('mail-services.index')
            ->with(self::STATUS_KEY, __(':email is connected and can send e-mail.', ['email' => $service->from_address]));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('mail-services.index')->with(self::ERROR_KEY, $message);
    }
}
