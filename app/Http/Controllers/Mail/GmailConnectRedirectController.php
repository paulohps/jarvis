<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailService;
use App\Services\Mail\GmailTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Sends the user to Google to let Jarvis send e-mail from their account
 * (scope `gmail.send`), with offline access and the consent screen every
 * time, so Google returns a refresh token. What the user filled in (name,
 * sender name, global) or the service being reconnected waits in the
 * session for the callback.
 *
 * The callback URL (`mail-services.google.callback`) must be among the
 * authorized redirect URIs of the Google OAuth client.
 */
class GmailConnectRedirectController extends Controller
{
    public const string SESSION_KEY = 'mail_services.google_connect';

    public function __invoke(Request $request): RedirectResponse
    {
        $serviceId = $request->query('service');

        if (is_string($serviceId) && $serviceId !== '') {
            $service = MailService::query()->where('friendly_id', $serviceId)->firstOrFail();
            Gate::authorize('update', $service);
        }

        $request->session()->put(self::SESSION_KEY, [
            'service' => $service->friendly_id ?? null,
            'name' => is_string($request->query('name')) ? $request->query('name') : null,
            'from_name' => is_string($request->query('from_name')) ? $request->query('from_name') : null,
            'is_global' => $request->boolean('is_global'),
        ]);

        /** @var GoogleProvider $google */
        $google = Socialite::driver('google');

        return $google
            ->redirectUrl(route('mail-services.google.callback'))
            ->scopes([GmailTransport::SCOPE])
            ->with(['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'])
            ->redirect();
    }
}
