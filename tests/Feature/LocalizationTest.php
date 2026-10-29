<?php

use App\Models\Locale;
use App\Models\User;
use App\Support\Localization;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\LocalizedUser;

function localizedUser(?string $locale): User
{
    if ($locale === null) {
        // No saved preference: SetLocale only depends on HasLocalePreference.
        $user = LocalizedUser::query()->findOrFail(User::factory()->create()->id);
        $user->savedLocale = null;

        return $user;
    }

    return User::factory()->create(['locale_id' => Locale::idFor($locale)]);
}

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Route::middleware('api')->post('/api/v1/localization-probe', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);

        return ['locale' => app()->getLocale()];
    });

    Route::middleware('web')->get('/localization-probe/month', fn () => Carbon::create(2026, 10, 1)->translatedFormat('F'));
});

test('authenticated user locale drives translations', function () {
    $this->actingAs(localizedUser('pt_BR'))
        ->get('/')
        ->assertOk()
        ->assertSee('Início');
});

test('guests see english', function () {
    $this->get('/login', ['Accept-Language' => 'pt-BR'])
        ->assertOk()
        ->assertSee('Sign in to Jarvis')
        ->assertDontSee('Entrar no Jarvis');
});

test('a user without a saved locale sees english', function () {
    $this->actingAs(localizedUser(null))
        ->get('/localization-probe/month')
        ->assertSee('October');
});

test('api honours accept language when unauthenticated', function () {
    $this->postJson('/api/v1/localization-probe', [], ['Accept-Language' => 'pt-BR'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'O campo email é obrigatório.');
});

test('api falls back to english for unsupported languages', function () {
    $this->postJson('/api/v1/localization-probe', [], ['Accept-Language' => 'de-DE'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email field is required.');
});

test('carbon follows the active locale', function () {
    $this->actingAs(localizedUser('pt_BR'))
        ->get('/localization-probe/month')
        ->assertSee('outubro');

    $this->actingAs(localizedUser('en'))
        ->get('/localization-probe/month')
        ->assertSee('October');
});

test('accept language variants map onto supported locales', function (string $header, ?string $expected) {
    $request = Request::create('/api/v1/anything', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

    expect(Localization::fromAcceptLanguage($request))->toBe($expected);
})->with([
    ['pt-BR', 'pt_BR'],
    ['pt', 'pt_BR'],
    ['pt-PT,en;q=0.5', 'pt_BR'],
    ['de-DE,en-US;q=0.8', 'en'],
    ['de-DE', null],
]);
