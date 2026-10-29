<?php

namespace App\Actions\Agenda;

use App\Models\GoogleCalendarAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Removes a connected Google account and its calendars from the agenda,
 * and asks Google to revoke Jarvis's access — on a best effort basis: the
 * account is removed even when Google cannot be reached.
 */
final readonly class DisconnectGoogleCalendarAccount
{
    public const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, GoogleCalendarAccount $account): void
    {
        if ($account->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $account->refresh_token]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $account->delete();
    }
}
