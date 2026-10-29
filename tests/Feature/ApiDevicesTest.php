<?php

use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->create();
});

test('registering a device links it to the current token', function () {
    $token = $this->user->createToken('iPhone da Ana');

    $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/devices', [
        'platform' => 'ios',
        'name' => 'iPhone da Ana',
        'push_token' => 'fcm-token-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'iPhone da Ana')
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.has_push_token', true)
        ->assertJsonPath('data.is_current', true)
        ->assertJsonMissingPath('data.push_token');

    $device = Device::query()->sole();

    expect($device->user_id)->toBe($this->user->id)
        ->and($device->personal_access_token_id)->toBe($token->accessToken->id)
        ->and($device->device_platform_id)->toBe(DevicePlatform::idFor('ios'))
        ->and($device->push_token)->toBe('fcm-token-1')
        ->and($device->last_used_at)->not->toBeNull()
        ->and($response->json('data.friendly_id'))->toBe($device->friendly_id);

    // The next call updates the same device; leaving the push token out keeps it.
    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/devices', ['platform' => 'ios', 'name' => 'iPhone 18'])
        ->assertOk()
        ->assertJsonPath('data.friendly_id', $device->friendly_id);

    expect(Device::query()->count())->toBe(1)
        ->and($device->fresh()->name)->toBe('iPhone 18')
        ->and($device->fresh()->push_token)->toBe('fcm-token-1');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/devices', ['platform' => 'ios', 'name' => 'iPhone 18', 'push_token' => null])
        ->assertOk()
        ->assertJsonPath('data.has_push_token', false);
});

test('duplicate push token is reassigned', function () {
    $someoneElse = User::factory()->create();
    $oldToken = $someoneElse->createToken('Pixel');
    $oldDevice = Device::factory()->for($someoneElse)->android()->create([
        'personal_access_token_id' => $oldToken->accessToken->id,
        'push_token' => 'shared-fcm-token',
    ]);

    $token = $this->user->createToken('Pixel');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/devices', ['platform' => 'android', 'name' => 'Pixel', 'push_token' => 'shared-fcm-token'])
        ->assertCreated();

    expect($oldDevice->fresh()->push_token)->toBeNull()
        ->and(Device::query()->where('push_token', 'shared-fcm-token')->sole()->personal_access_token_id)->toBe($token->accessToken->id);
});

test('registering a device validates its fields', function () {
    $token = $this->user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/devices', ['platform' => 'windows-phone', 'push_token' => str_repeat('a', 513)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platform', 'name', 'push_token']);

    expect(Device::query()->count())->toBe(0);
});

test('last use is the later of the device mark and its token', function () {
    $this->travelTo(now()->startOfSecond());

    $token = $this->user->createToken('iPhone');
    $phone = Device::factory()->for($this->user)->ios()->create([
        'personal_access_token_id' => $token->accessToken->id,
        'last_used_at' => now()->subWeek(),
    ]);
    $browser = Device::factory()->for($this->user)->web()->create(['last_used_at' => now()->subDay()]);

    // The request itself is the token's last use, so the phone comes first
    // and shows it, even though its own mark is the oldest.
    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/devices')
        ->assertOk()
        ->assertJsonPath('data.0.friendly_id', $phone->friendly_id)
        ->assertJsonPath('data.0.last_used_at', now()->toIso8601String())
        ->assertJsonPath('data.1.friendly_id', $browser->friendly_id)
        ->assertJsonPath('data.1.last_used_at', now()->subDay()->toIso8601String());
});

test('devices lists only the users own devices', function () {
    $token = $this->user->createToken('iPhone');
    $current = Device::factory()->for($this->user)->ios()->create([
        'name' => 'iPhone',
        'personal_access_token_id' => $token->accessToken->id,
        'last_used_at' => now()->subMinute(),
    ]);
    $other = Device::factory()->for($this->user)->android()->create(['name' => 'Pixel', 'last_used_at' => null]);
    Device::factory()->for(User::factory())->create(['name' => 'Their phone']);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/devices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.friendly_id', $current->friendly_id)
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.1.friendly_id', $other->friendly_id)
        ->assertJsonPath('data.1.platform', 'android')
        ->assertJsonPath('data.1.is_current', false)
        ->assertJsonPath('data.1.last_used_at', null);
});

test('deleting a device revokes it and its token', function () {
    $token = $this->user->createToken('iPhone');
    $phoneToken = $this->user->createToken('Old phone');
    $phone = Device::factory()->for($this->user)->withPushToken()->create(['personal_access_token_id' => $phoneToken->accessToken->id]);

    $this->withToken($token->plainTextToken)
        ->deleteJson("/api/v1/devices/{$phone->friendly_id}")
        ->assertNoContent();

    expect($phone->fresh())->toBeNull()
        ->and(PersonalAccessToken::query()->pluck('id')->all())->toBe([$token->accessToken->id]);

    app('auth')->forgetGuards();

    $this->withToken($phoneToken->plainTextToken)->getJson('/api/v1/devices')->assertUnauthorized();
});

test('user cannot delete another users device', function () {
    $someoneElse = User::factory()->create();
    $theirToken = $someoneElse->createToken('Their phone');
    $theirDevice = Device::factory()->for($someoneElse)->withPushToken()->create(['personal_access_token_id' => $theirToken->accessToken->id]);

    $this->withToken($this->user->createToken('iPhone')->plainTextToken)
        ->deleteJson("/api/v1/devices/{$theirDevice->friendly_id}")
        ->assertForbidden();

    $this->withToken($this->user->createToken('iPad')->plainTextToken)
        ->deleteJson('/api/v1/devices/dev_missing')
        ->assertNotFound();

    expect($theirDevice->fresh())->not->toBeNull()
        ->and(PersonalAccessToken::query()->whereKey($theirToken->accessToken->id)->exists())->toBeTrue();
});

test('devices require a token', function () {
    $this->getJson('/api/v1/devices')->assertUnauthorized();
    $this->postJson('/api/v1/devices', ['platform' => 'ios', 'name' => 'iPhone'])->assertUnauthorized();
});
