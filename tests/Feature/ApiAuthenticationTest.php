<?php

use App\Models\Device;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function () {
        Route::get('auth-probe', fn () => ['ok' => true]);
    });
});

test('api login returns a named sanctum token', function () {
    $user = User::factory()->withModules(['shopping'])->create(['email' => 'ana@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'Ana@Example.com',
        'password' => 'password',
        'device_name' => 'iPhone da Ana',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['friendly_id', 'name', 'email', 'avatar_url', 'locale', 'timezone', 'modules']])
        ->assertJsonPath('user.friendly_id', $user->friendly_id)
        ->assertJsonPath('user.modules.0.slug', 'shopping')
        ->assertJsonMissingPath('user.id');

    $token = PersonalAccessToken::findToken($response->json('token'));

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('iPhone da Ana')
        ->and($token->tokenable->is($user))->toBeTrue();

    $this->withToken($response->json('token'))->getJson('/api/v1/auth-probe')->assertOk();
});

test('api login with invalid credentials returns 422', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.com',
        'password' => 'wrong-password',
        'device_name' => 'iPhone',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
        'device_name' => 'iPhone',
    ]);

    $wrongPassword->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);
    $unknownEmail->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_name']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('a deactivated user cannot sign in to the api', function () {
    User::factory()->deactivated()->create(['email' => 'ana@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.com',
        'password' => 'password',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('api login shares the lockout of the web sign-in', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'wrong-password',
            'device_name' => 'iPhone',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.com',
        'password' => 'password',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonPath('errors.email.0', fn (string $message) => str_contains($message, 'seconds'));
});

test('api sign-in is limited per ip', function () {
    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => "user{$attempt}@example.com",
            'password' => 'password',
            'device_name' => 'iPhone',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'another@example.com',
        'password' => 'password',
        'device_name' => 'iPhone',
    ])->assertTooManyRequests();
});

test('api logout revokes only the current token', function () {
    $user = User::factory()->create();
    $phone = $user->createToken('iPhone');
    $tablet = $user->createToken('iPad');
    $phoneDevice = Device::factory()->for($user)->withPushToken()->create(['personal_access_token_id' => $phone->accessToken->id]);
    $tabletDevice = Device::factory()->for($user)->withPushToken()->create(['personal_access_token_id' => $tablet->accessToken->id]);

    $this->withToken($phone->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(PersonalAccessToken::query()->pluck('id')->all())->toBe([$tablet->accessToken->id])
        ->and($phoneDevice->fresh())->toBeNull()
        ->and($tabletDevice->fresh())->not->toBeNull();

    app('auth')->forgetGuards();

    $this->withToken($tablet->plainTextToken)->getJson('/api/v1/auth-probe')->assertOk();
});

test('revoked token gets 401', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth-probe')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
