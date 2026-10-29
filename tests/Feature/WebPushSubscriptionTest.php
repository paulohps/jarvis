<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Push\FcmClient;
use App\Services\Push\PushMessage;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Notifications\PushNotice;
use Tests\Fixtures\Push\FakeFcm;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->create();
});

test('guests cannot register a browser for push', function () {
    $this->postJson(route('push.subscriptions.store'), ['name' => 'Safari on iPhone', 'push_token' => 'web-token'])
        ->assertUnauthorized();
});

test('the browser registers as a web device and updates itself afterwards', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('push.subscriptions.store'), ['name' => 'Safari on iPhone', 'push_token' => 'web-token-1'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Safari on iPhone');

    $device = Device::query()->sole();

    expect($device->user_id)->toBe($this->user->id)
        ->and($device->device_platform_id)->toBe(DevicePlatform::idFor(DevicePlatform::WEB))
        ->and($device->personal_access_token_id)->toBeNull()
        ->and($device->push_token)->toBe('web-token-1')
        ->and($device->last_used_at)->not->toBeNull()
        ->and($response->json('data.friendly_id'))->toBe($device->friendly_id)
        ->and($device->isBrowser())->toBeTrue();

    // A refreshed token updates the same device.
    $this->actingAs($this->user)
        ->postJson(route('push.subscriptions.store'), ['name' => 'Safari on iPhone', 'push_token' => 'web-token-2', 'device' => $device->friendly_id])
        ->assertOk()
        ->assertJsonPath('data.friendly_id', $device->friendly_id);

    expect(Device::query()->count())->toBe(1)
        ->and($device->fresh()->push_token)->toBe('web-token-2');

    // A browser that lost its device id but still holds the token finds it again.
    $this->actingAs($this->user)
        ->postJson(route('push.subscriptions.store'), ['name' => 'Safari on iPhone', 'push_token' => 'web-token-2'])
        ->assertOk()
        ->assertJsonPath('data.friendly_id', $device->friendly_id);

    expect(Device::query()->count())->toBe(1);
});

test('a token moves to the browser that registered it last', function () {
    $other = Device::factory()->web()->create(['push_token' => 'shared-token']);
    $phone = Device::factory()->for($this->user)->create(['push_token' => 'phone-token']);

    $this->actingAs($this->user)
        ->postJson(route('push.subscriptions.store'), ['name' => 'Chrome on Android', 'push_token' => 'shared-token'])
        ->assertCreated();

    expect($other->fresh()->push_token)->toBeNull()
        ->and($phone->fresh()->push_token)->toBe('phone-token')
        ->and($this->user->devices()->where('push_token', 'shared-token')->count())->toBe(1);
});

test('registering needs a name and a token', function () {
    $this->actingAs($this->user)
        ->postJson(route('push.subscriptions.store'), ['name' => 'Chrome on Mac'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['push_token']);
});

test('the browser forgets its device only, never someone elses', function () {
    $mine = Device::factory()->for($this->user)->web()->withPushToken()->create();
    $theirs = Device::factory()->web()->withPushToken()->create();

    $this->actingAs($this->user)
        ->deleteJson(route('push.subscriptions.destroy', $theirs))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->deleteJson(route('push.subscriptions.destroy', $mine))
        ->assertNoContent();

    expect($mine->fresh())->toBeNull()
        ->and($theirs->fresh())->not->toBeNull();
});

test('the profile lists browsers among the devices and tells the browser it was revoked', function () {
    $browser = Device::factory()->for($this->user)->web()->withPushToken()->create(['name' => 'Safari on iPhone']);

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'notifications')
        ->assertSee('data-pwa-push-section', false)
        ->assertSee('data-pwa-push-state="not-set-up"', false)
        ->set('tab', 'devices')
        ->assertSee('data-pwa-section', false)
        ->assertDontSee('data-pwa-push-section', false)
        ->assertSee("data-device=\"{$browser->friendly_id}\"", false)
        ->assertSee('data-device-platform="web"', false)
        ->assertSee('Safari on iPhone')
        ->call('revokeDevice', $browser->id)
        ->assertDispatched('pwa:device-revoked', deviceId: $browser->friendly_id)
        ->assertDontSee("data-device=\"{$browser->friendly_id}\"", false);

    expect($browser->fresh())->toBeNull();
});

test('a push reaches the browser with what it shows and the page it opens', function () {
    config(['notifications.types' => [PushNotice::class]]);
    app(NotificationProvisioner::class)->provisionAll();
    FakeFcm::install();

    Device::factory()->for($this->user)->web()->create(['push_token' => 'web-token']);

    app(NotificationCenter::class)->dispatch(new PushNotice('the boiler'), $this->user);

    Http::assertSent(fn (Request $request): bool => FakeFcm::isSend($request)
        && $request['message']['token'] === 'web-token'
        && $request['message']['webpush']['notification']['icon'] === asset('icon-192.png')
        && $request['message']['webpush']['notification']['badge'] === asset('badge-96.png')
        && $request['message']['webpush']['fcm_options']['link'] === 'https://jarvis.example/home'
        && $request['message']['data']['deep_link'] === 'https://jarvis.example/home');
});

test('a link that is not https stays out of the webpush options', function () {
    FakeFcm::install();

    app(FcmClient::class)->send('web-token', new PushMessage('Hi', 'There', 'http://localhost/home', 'chat-1'));

    Http::assertSent(fn (Request $request): bool => FakeFcm::isSend($request)
        && ! isset($request['message']['webpush']['fcm_options'])
        && $request['message']['webpush']['notification']['tag'] === 'chat-1'
        && $request['message']['webpush']['notification']['renotify'] === true
        && $request['message']['webpush']['headers']['Urgency'] === 'high'
        && $request['message']['data']['deep_link'] === 'http://localhost/home');
});
