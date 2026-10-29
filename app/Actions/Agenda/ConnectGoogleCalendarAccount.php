<?php

namespace App\Actions\Agenda;

use App\Actions\Reminders\AuthorizesRemindersModule;
use App\Models\GoogleCalendarAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Stores the Google account a user allowed Jarvis to read the calendars of
 * (after the OAuth callback): a new one, or the same e-mail connected again
 * — its new refresh token replaces the old one and its failure is cleared.
 * Then its calendars are listed (SyncGoogleCalendars); a failure there
 * leaves the account connected, without calendars yet.
 */
final readonly class ConnectGoogleCalendarAccount
{
    use AuthorizesRemindersModule;

    public function __construct(private SyncGoogleCalendars $syncCalendars) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException when Google gave no refresh token
     */
    public function __invoke(User $actor, string $email, ?string $refreshToken): GoogleCalendarAccount
    {
        $this->authorizeReminders($actor);

        if (blank($refreshToken)) {
            throw ValidationException::withMessages([
                'google' => [__('Google did not give Jarvis lasting access. Remove Jarvis from your Google account’s third-party access and connect again.')],
            ]);
        }

        $account = GoogleCalendarAccount::query()->updateOrCreate(
            ['user_id' => $actor->id, 'email' => mb_strtolower($email)],
            ['refresh_token' => $refreshToken, 'failed_at' => null, 'last_error' => null],
        );

        try {
            ($this->syncCalendars)($actor, $account);
        } catch (ValidationException) {
            // Listed later, from the agenda.
        }

        return $account;
    }
}
