<?php

use App\Actions\Devices\RevokeDevice;
use App\Livewire\Profile\ProfilePage;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->create();

    Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function () {
        Route::get('device-probe', fn () => ['ok' => true]);
    });
});

/**
 * A device signed in to the app, with its Sanctum token.
 *
 * @return array{0: Device, 1: string} the device and the plain token
 */
function signedInDevice(User $user, string $name = 'iPhone'): array
{
    $token = $user->createToken($name);

    $device = Device::factory()->for($user)->withPushToken()->create([
        'name' => $name,
        'personal_access_token_id' => $token->accessToken->id,
        'last_used_at' => now()->subHour(),
    ]);

    return [$device, $token->plainTextToken];
}

test('revoking a device revokes its api token', function () {
    [$phone, $phoneToken] = signedInDevice($this->user, 'iPhone');
    [$tablet, $tabletToken] = signedInDevice($this->user, 'Pixel Tablet');

    $this->withToken($phoneToken)->getJson('/api/v1/device-probe')->assertOk();

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'devices')
        ->assertSee("data-device=\"{$phone->friendly_id}\"", false)
        ->call('revokeDevice', $phone->id)
        ->assertDispatched('ts-ui:toast')
        ->assertDontSee("data-device=\"{$phone->friendly_id}\"", false)
        ->assertSee("data-device=\"{$tablet->friendly_id}\"", false);

    expect($phone->fresh())->toBeNull()
        ->and(Device::query()->whereNotNull('push_token')->pluck('id')->all())->toBe([$tablet->id])
        ->and(PersonalAccessToken::query()->pluck('id')->all())->toBe([$tablet->personal_access_token_id]);

    app('auth')->forgetGuards();

    $this->withToken($phoneToken)->getJson('/api/v1/device-probe')->assertUnauthorized();

    app('auth')->forgetGuards();

    $this->withToken($tabletToken)->getJson('/api/v1/device-probe')->assertOk();
});

test('user cannot revoke another users device', function () {
    $someoneElse = User::factory()->create();
    [$theirDevice, $theirToken] = signedInDevice($someoneElse, 'Their phone');
    [$myDevice] = signedInDevice($this->user, 'My phone');

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'devices')
        ->assertSee('My phone')
        ->assertDontSee('Their phone')
        ->call('revokeDevice', $theirDevice->id)
        ->assertForbidden();

    expect(fn () => app(RevokeDevice::class)($this->user, $theirDevice))->toThrow(AuthorizationException::class)
        ->and($theirDevice->fresh())->not->toBeNull()
        ->and($theirDevice->fresh()->push_token)->not->toBeNull()
        ->and(PersonalAccessToken::query()->whereKey($theirDevice->personal_access_token_id)->exists())->toBeTrue()
        ->and($myDevice->fresh())->not->toBeNull();

    $this->withToken($theirToken)->getJson('/api/v1/device-probe')->assertOk();
});

test('the devices tab lists name platform and last use', function () {
    $this->user->update(['timezone' => 'America/Sao_Paulo']);

    Device::factory()->for($this->user)->ios()->create(['name' => 'iPhone da Ana', 'last_used_at' => '2026-10-01 15:30:00']);
    Device::factory()->for($this->user)->android()->create(['name' => 'Pixel', 'last_used_at' => null]);

    $this->actingAs($this->user)
        ->get(route('profile', ['tab' => 'devices']))
        ->assertOk()
        ->assertSeeInOrder(['iPhone da Ana', 'iOS', '10/01/2026 12:30', 'Pixel', 'Android', '—']);
});

test('the devices tab shows the token use when it is more recent', function () {
    $this->user->update(['timezone' => 'America/Sao_Paulo']);

    $token = $this->user->createToken('iPhone');
    $token->accessToken->forceFill(['last_used_at' => '2026-10-02 18:00:00'])->save();
    Device::factory()->for($this->user)->ios()->create([
        'name' => 'iPhone da Ana',
        'personal_access_token_id' => $token->accessToken->id,
        'last_used_at' => '2026-10-01 15:30:00',
    ]);

    $this->actingAs($this->user)
        ->get(route('profile', ['tab' => 'devices']))
        ->assertOk()
        ->assertSeeInOrder(['iPhone da Ana', 'iOS', '10/02/2026 15:00'])
        ->assertDontSee('10/01/2026 12:30');
});

test('a user without devices sees an empty list', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'devices')
        ->assertSee('No devices')
        ->assertDontSee('data-device-list', false);
});

test('a device without a token is revoked too', function () {
    $device = Device::factory()->for($this->user)->withPushToken()->create();

    app(RevokeDevice::class)($this->user, $device);

    expect($device->fresh())->toBeNull();
});
