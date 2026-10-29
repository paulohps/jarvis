<?php

namespace App\Services\Agenda\Google;

use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads a connected Google account with the Calendar API v3, read only:
 * its calendar list and the events of a calendar in a window — recurring
 * events expanded into their instances (`singleEvents`), the cancelled
 * ones and the ones the account declined left out.
 *
 * The stored refresh token buys an access token, kept in the cache until
 * shortly before it expires; the events of a window are cached for
 * EVENTS_TTL seconds, so the agenda does not call Google on every render —
 * as plain arrays, since the cache does not unserialize objects
 * (`cache.serializable_classes`).
 * A refresh token Google no longer accepts (`invalid_grant`: revoked,
 * password changed, or older than 7 days while the OAuth app is in
 * "Testing") marks the account as failed.
 */
final class GoogleCalendarClient
{
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string API_URL = 'https://www.googleapis.com/calendar/v3';

    /**
     * @var list<string>
     */
    public const array SCOPES = [
        'https://www.googleapis.com/auth/calendar.events.readonly',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];

    public const int EVENTS_TTL = 300;

    /**
     * The most pages of events read for one window.
     */
    private const int MAX_PAGES = 4;

    private const int TIMEOUT = 15;

    /**
     * The account's calendars, as Google lists them.
     *
     * @return list<array{google_id: string, name: string, color: string|null, is_primary: bool, is_selected: bool}>
     *
     * @throws GoogleCalendarException
     */
    public function calendars(GoogleCalendarAccount $account): array
    {
        $calendars = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->get($account, '/users/me/calendarList', array_filter([
                'maxResults' => 250,
                'pageToken' => $pageToken,
            ]));

            foreach ((array) $response->json('items', []) as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }

                $calendars[] = [
                    'google_id' => $item['id'],
                    'name' => is_string($item['summaryOverride'] ?? null) ? $item['summaryOverride'] : (string) ($item['summary'] ?? $item['id']),
                    'color' => is_string($item['backgroundColor'] ?? null) ? $item['backgroundColor'] : null,
                    'is_primary' => ($item['primary'] ?? false) === true,
                    'is_selected' => ($item['selected'] ?? false) === true,
                ];
            }

            $pageToken = $response->json('nextPageToken');

            if (! is_string($pageToken)) {
                break;
            }
        }

        return $calendars;
    }

    /**
     * The events of `$calendar` overlapping [`$from`, `$to`), cached.
     *
     * @return list<GoogleEvent>
     *
     * @throws GoogleCalendarException
     */
    public function events(GoogleCalendar $calendar, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $generation = (int) Cache::get($this->generationKey($calendar->account), 0);
        $key = "agenda:google-events:v2:{$calendar->id}:{$generation}:{$from->getTimestamp()}:{$to->getTimestamp()}";

        /** @var list<array{id: string, title: string, starts_at: string|null, ends_at: string|null, start_date: string|null, end_date: string|null, url: string|null}> $events */
        $events = Cache::remember($key, self::EVENTS_TTL, fn (): array => array_map(
            fn (GoogleEvent $event): array => $event->toArray(),
            $this->fetchEvents($calendar, $from, $to),
        ));

        return array_map(GoogleEvent::fromArray(...), $events);
    }

    /**
     * Forgets the cached events of the account's calendars, so the next read
     * asks Google.
     */
    public function forget(GoogleCalendarAccount $account): void
    {
        Cache::put($this->generationKey($account), (int) Cache::get($this->generationKey($account), 0) + 1);
    }

    /**
     * @return list<GoogleEvent>
     *
     * @throws GoogleCalendarException
     */
    private function fetchEvents(GoogleCalendar $calendar, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->get($calendar->account, '/calendars/'.rawurlencode($calendar->google_id).'/events', array_filter([
                'timeMin' => $from->utc()->toRfc3339String(),
                'timeMax' => $to->utc()->toRfc3339String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));

            foreach ((array) $response->json('items', []) as $item) {
                $event = is_array($item) ? $this->event($item) : null;

                if ($event !== null) {
                    $events[] = $event;
                }
            }

            $pageToken = $response->json('nextPageToken');

            if (! is_string($pageToken)) {
                break;
            }
        }

        return $events;
    }

    /**
     * @param  array<mixed>  $item
     */
    private function event(array $item): ?GoogleEvent
    {
        if (! is_string($item['id'] ?? null) || ($item['status'] ?? null) === 'cancelled') {
            return null;
        }

        foreach ((array) ($item['attendees'] ?? []) as $attendee) {
            if (is_array($attendee) && ($attendee['self'] ?? false) === true && ($attendee['responseStatus'] ?? null) === 'declined') {
                return null;
            }
        }

        $start = (array) ($item['start'] ?? []);
        $end = (array) ($item['end'] ?? []);
        $title = is_string($item['summary'] ?? null) && $item['summary'] !== '' ? $item['summary'] : __('(No title)');
        $url = is_string($item['htmlLink'] ?? null) ? $item['htmlLink'] : null;

        if (is_string($start['date'] ?? null)) {
            return new GoogleEvent(
                id: $item['id'],
                title: $title,
                startsAt: null,
                endsAt: null,
                startDate: $start['date'],
                endDate: is_string($end['date'] ?? null) ? $end['date'] : null,
                url: $url,
            );
        }

        if (! is_string($start['dateTime'] ?? null)) {
            return null;
        }

        return new GoogleEvent(
            id: $item['id'],
            title: $title,
            startsAt: CarbonImmutable::parse($start['dateTime'])->utc(),
            endsAt: is_string($end['dateTime'] ?? null) ? CarbonImmutable::parse($end['dateTime'])->utc() : null,
            startDate: null,
            endDate: null,
            url: $url,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws GoogleCalendarException
     */
    private function get(GoogleCalendarAccount $account, string $path, array $query): Response
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withToken($this->accessToken($account))
                ->get(self::API_URL.$path, $query);
        } catch (ConnectionException $exception) {
            throw new GoogleCalendarException(__('Google could not be reached: :message', ['message' => $exception->getMessage()]), 0, $exception);
        }

        if ($response->failed()) {
            throw new GoogleCalendarException(__('Google refused the connection: :message', ['message' => $this->errorOf($response)]));
        }

        return $response;
    }

    /**
     * A valid access token, refreshed when the cached one is gone.
     *
     * @throws GoogleCalendarException
     */
    private function accessToken(GoogleCalendarAccount $account): string
    {
        $key = 'agenda:google-token:'.$account->id.':'.hash('sha256', $account->refresh_token);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(self::TOKEN_URL, [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $exception) {
            throw new GoogleCalendarException(__('Google could not be reached: :message', ['message' => $exception->getMessage()]), 0, $exception);
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            $message = $response->json('error') === 'invalid_grant'
                ? __('Google no longer accepts this account’s connection (revoked or expired). Connect the Google account again.')
                : __('Google refused the connection: :message', ['message' => $this->errorOf($response)]);

            if ($response->json('error') === 'invalid_grant') {
                $account->update(['failed_at' => now(), 'last_error' => $message]);
            }

            throw new GoogleCalendarException($message);
        }

        Cache::put($key, $token, now()->addSeconds(max(60, (int) ($response->json('expires_in') ?? 3600) - 120)));

        return $token;
    }

    private function generationKey(GoogleCalendarAccount $account): string
    {
        return 'agenda:google-generation:'.$account->id;
    }

    private function errorOf(Response $response): string
    {
        $error = $response->json('error');

        if (is_array($error)) {
            return (string) ($error['message'] ?? $response->status());
        }

        return is_string($error) ? (string) ($response->json('error_description') ?? $error) : (string) $response->status();
    }
}
