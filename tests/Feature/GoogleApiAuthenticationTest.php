<?php

use App\Models\Invitation;
use App\Models\Module;
use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config([
        'services.google.client_id' => 'web-client.apps.googleusercontent.com',
        'services.google.ios_client_id' => 'ios-client.apps.googleusercontent.com',
        'services.google.android_client_id' => 'android-client.apps.googleusercontent.com',
    ]);
});

/**
 * Fakes Google's `tokeninfo` answering the given claims for any ID token.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeGoogleTokenInfo(array $claims = [], int $status = 200): void
{
    Http::fake([
        'oauth2.googleapis.com/tokeninfo*' => Http::response([
            'iss' => 'https://accounts.google.com',
            'aud' => 'ios-client.apps.googleusercontent.com',
            'sub' => '1234567890',
            'email' => 'ana@example.com',
            'email_verified' => 'true',
            'name' => 'Ana Google',
            'exp' => (string) now()->addHour()->getTimestamp(),
            ...$claims,
        ], $status),
    ]);
}

/**
 * @param  list<string>  $moduleSlugs
 */
function pendingApiInvitationFor(string $email, array $moduleSlugs = []): Invitation
{
    $invitation = Invitation::factory()->create(['email' => $email]);

    $invitation->modules()->sync(array_map(fn (string $slug): int => Module::idFor($slug), $moduleSlugs));

    return $invitation;
}

test('google api login creates account from pending invitation', function () {
    $invitation = pendingApiInvitationFor('ana@example.com', [Module::SHOPPING, Module::REMINDERS]);
    fakeGoogleTokenInfo();

    $response = $this->postJson('/api/v1/auth/google', [
        'id_token' => 'the-id-token',
        'device_name' => 'Pixel da Ana',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'ana@example.com')
        ->assertJsonPath('user.name', 'Ana Google')
        ->assertJsonPath('user.modules.*.slug', [Module::SHOPPING, Module::REMINDERS]);

    $user = User::query()->where('email', 'ana@example.com')->sole();
    $token = PersonalAccessToken::findToken($response->json('token'));

    expect($token->name)->toBe('Pixel da Ana')
        ->and($token->tokenable->is($user))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(SocialAccount::query()->where('user_id', $user->id)->value('provider_user_id'))->toBe('1234567890');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://oauth2.googleapis.com/tokeninfo')
        && $request['id_token'] === 'the-id-token');
});

test('google api login signs in an existing account from any of our clients', function (string $audience) {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    fakeGoogleTokenInfo(['aud' => $audience, 'iss' => 'accounts.google.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'])
        ->assertOk()
        ->assertJsonPath('user.friendly_id', $user->friendly_id);
})->with([
    'ios' => 'ios-client.apps.googleusercontent.com',
    'android' => 'android-client.apps.googleusercontent.com',
    'web' => 'web-client.apps.googleusercontent.com',
]);

test('google token with foreign audience is rejected', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    fakeGoogleTokenInfo(['aud' => 'someone-else.apps.googleusercontent.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'This Google sign-in is not valid. Try again.']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('google token with a foreign issuer, an expired one or refused by google is rejected', function (array $claims, int $status) {
    User::factory()->create(['email' => 'ana@example.com']);
    fakeGoogleTokenInfo($claims, $status);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'This Google sign-in is not valid. Try again.']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'foreign issuer' => [['iss' => 'https://evil.example.com'], 200],
    'expired' => [['exp' => '1000'], 200],
    'invalid token' => [[], 400],
]);

test('google api login without invitation is refused', function () {
    fakeGoogleTokenInfo(['email' => 'stranger@example.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'Invitation required: ask the admin to invite this Google account e-mail.']);

    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('google api refusal comes in the language the app asks for', function () {
    fakeGoogleTokenInfo(['email' => 'stranger@example.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'], ['Accept-Language' => 'pt-BR'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'Convite necessário: peça ao admin para convidar o e-mail desta conta Google.']);
});

test('google api login refuses a deactivated account', function () {
    User::factory()->deactivated()->create(['email' => 'ana@example.com']);
    fakeGoogleTokenInfo();

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'This account is deactivated.']);
});

test('google api login refuses an unverified e-mail', function () {
    fakeGoogleTokenInfo(['email' => 'bob@example.com', 'email_verified' => 'false']);
    pendingApiInvitationFor('bob@example.com');

    $this->postJson('/api/v1/auth/google', ['id_token' => 'the-id-token', 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'This Google account has no verified e-mail.']);
});

test('google api login requires the id token and the device name', function () {
    Http::fake();

    $this->postJson('/api/v1/auth/google', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token', 'device_name']);

    Http::assertNothingSent();
});
