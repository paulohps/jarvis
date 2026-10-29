<?php

namespace App\Actions\Agenda;

use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarAccount;
use App\Models\User;
use App\Services\Agenda\Google\GoogleCalendarClient;
use App\Services\Agenda\Google\GoogleCalendarException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings a connected account's calendars up to date with Google: new ones
 * shown when Google shows them, the known ones renamed and recolored but
 * keeping whether they are shown here, the ones gone removed. Also drops
 * the cached events, so the agenda reads Google again. Only the account's
 * owner.
 */
final readonly class SyncGoogleCalendars
{
    public function __construct(private GoogleCalendarClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException when Google cannot be read
     */
    public function __invoke(User $actor, GoogleCalendarAccount $account): GoogleCalendarAccount
    {
        if ($account->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        try {
            $calendars = $this->client->calendars($account);
        } catch (GoogleCalendarException $exception) {
            throw ValidationException::withMessages(['google' => [$exception->getMessage()]]);
        }

        DB::transaction(function () use ($account, $calendars): void {
            foreach ($calendars as $calendar) {
                $existing = $account->calendars()->where('google_id', $calendar['google_id'])->first();

                if ($existing === null) {
                    GoogleCalendar::query()->create([
                        'google_calendar_account_id' => $account->id,
                        'google_id' => $calendar['google_id'],
                        'name' => mb_substr($calendar['name'], 0, 255),
                        'color' => $calendar['color'],
                        'is_primary' => $calendar['is_primary'],
                        'is_visible' => $calendar['is_primary'] || $calendar['is_selected'],
                    ]);

                    continue;
                }

                $existing->update([
                    'name' => mb_substr($calendar['name'], 0, 255),
                    'color' => $calendar['color'],
                    'is_primary' => $calendar['is_primary'],
                ]);
            }

            $account->calendars()->whereNotIn('google_id', array_column($calendars, 'google_id'))->delete();
            $account->update(['failed_at' => null, 'last_error' => null]);
        });

        $this->client->forget($account);

        return $account;
    }
}
