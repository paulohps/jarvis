<?php

use App\Http\Controllers\Agenda\GoogleCalendarConnectCallbackController;
use App\Livewire\Agenda\AgendaPage;
use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarAccount;
use App\Models\Module;
use App\Models\User;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\Google\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    // 09:00 on Monday, October 5, in São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::REMINDERS])->create(['timezone' => 'America/Sao_Paulo']);
});

/**
 * Google answering: a token for any refresh token, the calendar list, and
 * the events of each calendar by its id.
 *
 * @param  array<string, list<array<string, mixed>>>  $eventsByCalendar
 */
function fakeGoogle(array $eventsByCalendar = [], array $calendarList = []): void
{
    Http::fake(function (Request $request) use ($eventsByCalendar, $calendarList) {
        $url = $request->url();

        if (str_starts_with($url, GoogleCalendarClient::TOKEN_URL)) {
            return Http::response(['access_token' => 'access-token', 'expires_in' => 3600]);
        }

        if (str_contains($url, '/users/me/calendarList')) {
            return Http::response(['items' => $calendarList]);
        }

        foreach ($eventsByCalendar as $calendarId => $events) {
            if (str_contains($url, '/calendars/'.rawurlencode($calendarId).'/events')) {
                return Http::response(['items' => $events]);
            }
        }

        return Http::response([], 404);
    });
}

function googleCalendarOf(User $user, string $googleId = 'paulo@gmail.com', array $attributes = []): GoogleCalendar
{
    $account = GoogleCalendarAccount::factory()->for($user)->create(['email' => 'paulo@gmail.com']);

    return GoogleCalendar::factory()->for($account, 'account')->create(['google_id' => $googleId, 'name' => 'Paulo', ...$attributes]);
}

/**
 * The reader's calendar of the week, `title|start (UTC)|all day`.
 *
 * @return list<string>
 */
function googleWeek(User $reader): array
{
    return array_map(
        fn (CalendarEntry $entry): string => $entry->title.'|'.$entry->startsAt->format('Y-m-d H:i').($entry->isAllDay ? '|all day' : ''),
        app(CalendarFeed::class)->between($reader, CarbonImmutable::parse('2026-10-05 03:00', 'UTC'), CarbonImmutable::parse('2026-10-12 03:00', 'UTC')),
    );
}

test('the reader sees the events of their own shown google calendars', function () {
    googleCalendarOf($this->user);
    googleCalendarOf(User::factory()->withModules([Module::REMINDERS])->create(), 'other@gmail.com');

    fakeGoogle(['paulo@gmail.com' => [
        ['id' => 'e1', 'status' => 'confirmed', 'summary' => 'Dentist', 'htmlLink' => 'https://calendar.google.com/e1',
            'start' => ['dateTime' => '2026-10-06T14:00:00-03:00'], 'end' => ['dateTime' => '2026-10-06T15:30:00-03:00']],
        ['id' => 'e2', 'status' => 'confirmed', 'summary' => 'Trip',
            'start' => ['date' => '2026-10-08'], 'end' => ['date' => '2026-10-10']],
        ['id' => 'e3', 'status' => 'cancelled', 'summary' => 'Cancelled',
            'start' => ['dateTime' => '2026-10-07T10:00:00-03:00'], 'end' => ['dateTime' => '2026-10-07T11:00:00-03:00']],
        ['id' => 'e4', 'status' => 'confirmed', 'summary' => 'Declined',
            'attendees' => [['email' => 'paulo@gmail.com', 'self' => true, 'responseStatus' => 'declined']],
            'start' => ['dateTime' => '2026-10-07T10:00:00-03:00'], 'end' => ['dateTime' => '2026-10-07T11:00:00-03:00']],
    ]]);

    $entries = app(CalendarFeed::class)->between($this->user, CarbonImmutable::parse('2026-10-05 03:00', 'UTC'), CarbonImmutable::parse('2026-10-12 03:00', 'UTC'));

    expect(googleWeek($this->user))->toBe([
        'Dentist|2026-10-06 17:00',
        // An all-day event on each of its days.
        'Trip|2026-10-08 15:00|all day',
        'Trip|2026-10-09 15:00|all day',
    ])
        ->and($entries[0]->source)->toBe(CalendarFeed::GOOGLE)
        ->and($entries[0]->endsAt?->toIso8601String())->toBe('2026-10-06T18:30:00+00:00')
        ->and($entries[0]->url)->toBe('https://calendar.google.com/e1')
        ->and($entries[0]->subtitle)->toBe('Paulo');

    // Read once in the cache's life.
    googleWeek($this->user);
    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/events')
        && $request['singleEvents'] === 'true'
        && $request['timeMin'] === '2026-10-05T03:00:00+00:00'
        && $request->hasHeader('Authorization', 'Bearer access-token'));
});

test('the events read again from a cache that serializes, as redis does', function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::purge('array');

    googleCalendarOf($this->user);
    fakeGoogle(['paulo@gmail.com' => [
        ['id' => 'e1', 'summary' => 'Dentist', 'start' => ['dateTime' => '2026-10-06T14:00:00-03:00'], 'end' => ['dateTime' => '2026-10-06T15:30:00-03:00']],
        ['id' => 'e2', 'summary' => 'Trip', 'start' => ['date' => '2026-10-08'], 'end' => ['date' => '2026-10-09']],
    ]]);

    $expected = ['Dentist|2026-10-06 17:00', 'Trip|2026-10-08 15:00|all day'];

    expect(googleWeek($this->user))->toBe($expected)
        ->and(googleWeek($this->user))->toBe($expected);
    Http::assertSentCount(2);
});

test('a hidden calendar or a failed account stays off the agenda', function () {
    $hidden = googleCalendarOf($this->user, attributes: ['is_visible' => false]);
    fakeGoogle(['paulo@gmail.com' => [['id' => 'e1', 'summary' => 'Dentist', 'start' => ['dateTime' => '2026-10-06T14:00:00-03:00'], 'end' => ['dateTime' => '2026-10-06T15:00:00-03:00']]]]);

    expect(googleWeek($this->user))->toBe([]);

    $hidden->update(['is_visible' => true]);
    $hidden->account->update(['failed_at' => now(), 'last_error' => 'Revoked']);

    expect(googleWeek($this->user))->toBe([]);
    Http::assertNothingSent();
});

test('a connection google no longer accepts marks the account failed and the agenda still opens', function () {
    $calendar = googleCalendarOf($this->user);
    Http::fake([GoogleCalendarClient::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(googleWeek($this->user))->toBe([])
        ->and($calendar->account->refresh()->hasFailed())->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class)
        ->assertOk()
        ->assertSeeHtml('data-agenda-google-failed="'.$calendar->account->friendly_id.'"');
});

test('connecting a google account stores it with its calendars', function () {
    Socialite::fake('google', (new SocialiteUser)->map(['id' => '42', 'email' => 'Paulo@Gmail.com'])
        ->setRefreshToken('1//refresh-token')
        ->setApprovedScopes(['openid', ...GoogleCalendarClient::SCOPES]));
    fakeGoogle(calendarList: [
        ['id' => 'paulo@gmail.com', 'summary' => 'Paulo', 'primary' => true, 'backgroundColor' => '#9fe1e7'],
        ['id' => 'family', 'summary' => 'Family', 'selected' => true],
        ['id' => 'holidays', 'summary' => 'Holidays'],
    ]);

    $this->actingAs($this->user)
        ->get(route('reminders.google.redirect'))
        ->assertRedirect('https://socialite.fake/google/authorize');

    $this->actingAs($this->user)
        ->get(route('reminders.google.callback', ['code' => 'the-code', 'state' => 'x']))
        ->assertRedirect(route('reminders.index'))
        ->assertSessionHas(GoogleCalendarConnectCallbackController::STATUS_KEY);

    $account = GoogleCalendarAccount::query()->sole();

    expect($account->email)->toBe('paulo@gmail.com')
        ->and($account->refresh_token)->toBe('1//refresh-token')
        ->and($account->calendars->map(fn (GoogleCalendar $calendar): string => $calendar->name.($calendar->is_visible ? '' : ' (hidden)'))->all())
        ->toBe(['Paulo', 'Family', 'Holidays (hidden)'])
        ->and($account->calendars->first()->color)->toBe('#9fe1e7');
});

test('a connection without both read scopes or a refresh token is refused', function (array $scopes, ?string $refreshToken) {
    Socialite::fake('google', (new SocialiteUser)->map(['id' => '42', 'email' => 'paulo@gmail.com'])
        ->setRefreshToken($refreshToken)
        ->setApprovedScopes($scopes));
    fakeGoogle();

    $this->actingAs($this->user)->get(route('reminders.google.redirect'));

    $this->actingAs($this->user)
        ->get(route('reminders.google.callback', ['code' => 'the-code', 'state' => 'x']))
        ->assertRedirect(route('reminders.index'))
        ->assertSessionHas(GoogleCalendarConnectCallbackController::ERROR_KEY);

    expect(GoogleCalendarAccount::query()->count())->toBe(0);
})->with([
    'events scope missing' => [[GoogleCalendarClient::SCOPES[1]], '1//refresh-token'],
    'no refresh token' => [GoogleCalendarClient::SCOPES, null],
]);

test('the sheet shows, hides, refreshes and disconnects the reader\'s own accounts', function () {
    $calendar = googleCalendarOf($this->user);
    $account = $calendar->account;
    $others = googleCalendarOf(User::factory()->withModules([Module::REMINDERS])->create(), 'other@gmail.com');
    fakeGoogle(calendarList: [['id' => 'paulo@gmail.com', 'summary' => 'Paulo renamed', 'primary' => true]]);

    $component = Livewire::actingAs($this->user)
        ->test(AgendaPage::class)
        ->call('openGoogle')
        ->assertSeeHtml('data-agenda-google-account="'.$account->friendly_id.'"')
        ->assertDontSeeHtml('data-agenda-google-account="'.$others->account->friendly_id.'"')
        ->call('toggleGoogleCalendar', $calendar->friendly_id);

    expect($calendar->refresh()->is_visible)->toBeFalse();

    $component->call('syncGoogle', $account->friendly_id);

    // Renamed, still hidden.
    expect($calendar->refresh()->name)->toBe('Paulo renamed')
        ->and($calendar->is_visible)->toBeFalse();

    Livewire::actingAs($this->user)
        ->test(AgendaPage::class)
        ->call('toggleGoogleCalendar', $others->friendly_id)
        ->assertNotFound();

    expect($others->refresh()->is_visible)->toBeTrue();

    $component->call('disconnectGoogle', $account->friendly_id)
        ->assertSee('paulo@gmail.com was disconnected.');

    expect(GoogleCalendarAccount::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(GoogleCalendar::query()->whereKey($calendar->id)->exists())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === $account->refresh_token);
});
