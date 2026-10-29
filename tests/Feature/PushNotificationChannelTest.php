<?php

use App\Exceptions\FcmException;
use App\Models\Device;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Services\Notifications\Channels\PushChannel;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationGate;
use App\Services\Notifications\NotificationProvisioner;
use Database\Seeders\LookupSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\Notifications\PushNotice;
use Tests\Fixtures\Push\FakeFcm;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['notifications.types' => [PushNotice::class]]);
    app(NotificationProvisioner::class)->provisionAll();

    FakeFcm::install();

    $this->user = User::factory()->create();
});

/**
 * The registration tokens FCM was asked to send to, in order.
 *
 * @return list<string>
 */
function pushedTokens(): array
{
    return Http::recorded(fn (Request $request): bool => FakeFcm::isSend($request))
        ->map(fn (array $pair): string => $pair[0]['message']['token'])
        ->values()
        ->all();
}

test('push is sent to every device with a token', function () {
    $this->freezeTime();
    $yesterday = now()->subDay();

    $phone = Device::factory()->for($this->user)->create(['push_token' => 'phone-token', 'last_used_at' => $yesterday]);
    $tablet = Device::factory()->for($this->user)->android()->create(['push_token' => 'tablet-token', 'last_used_at' => null]);
    $silent = Device::factory()->for($this->user)->create(['push_token' => null, 'last_used_at' => $yesterday]);
    Device::factory()->create(['push_token' => 'someone-elses-token']);

    expect(app(NotificationGate::class)->channelsFor(new PushNotice, $this->user))->toBe([NotificationChannel::DATABASE, NotificationChannel::PUSH]);

    app(NotificationCenter::class)->dispatch(new PushNotice('the boiler'), $this->user);

    expect(pushedTokens())->toBe(['phone-token', 'tablet-token']);

    // Plain text: no tags, no entities, the paragraphs kept.
    Http::assertSent(fn (Request $request): bool => FakeFcm::isSend($request)
        && $request['message']['notification'] === [
            'title' => 'Check the boiler',
            'body' => "Time to check the boiler & the pipes.\n\nThanks!",
        ]);

    // The in-app copy goes out as well, and the tokens stay.
    expect($this->user->notifications()->count())->toBe(1)
        ->and($phone->fresh()->push_token)->toBe('phone-token')
        ->and($tablet->fresh()->push_token)->toBe('tablet-token');

    // The devices that got the push were just used; the one without a token was not.
    expect($phone->fresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($tablet->fresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($silent->fresh()->last_used_at?->toDateTimeString())->toBe($yesterday->toDateTimeString());
});

test('push channel is skipped without a device token or without fcm', function () {
    $gate = app(NotificationGate::class);

    expect($gate->channelsFor(new PushNotice, $this->user))->toBe([NotificationChannel::DATABASE]);

    Device::factory()->for($this->user)->create(['push_token' => 'phone-token']);
    config(['services.firebase.credentials' => null]);

    expect($gate->channelsFor(new PushNotice, $this->user))->toBe([NotificationChannel::DATABASE]);

    app(NotificationCenter::class)->dispatch(new PushNotice, $this->user);

    expect($this->user->notifications()->count())->toBe(1);
    Http::assertNothingSent();

    // An address without an account never gets a push.
    expect($gate->mayReceive(new PushNotice, Notification::route('mail', 'guest@example.com'), NotificationChannel::PUSH))->toBeFalse();
});

test('invalid token is removed', function () {
    FakeFcm::install(['gone-token' => 'UNREGISTERED', 'garbled-token' => 'INVALID_ARGUMENT']);

    $gone = Device::factory()->for($this->user)->create(['push_token' => 'gone-token']);
    $garbled = Device::factory()->for($this->user)->create(['push_token' => 'garbled-token']);
    $valid = Device::factory()->for($this->user)->create(['push_token' => 'valid-token']);

    app(NotificationCenter::class)->dispatch(new PushNotice, $this->user);

    // Every device was tried; the rejected tokens are gone, the others kept.
    expect(pushedTokens())->toBe(['gone-token', 'garbled-token', 'valid-token'])
        ->and($gone->fresh()->push_token)->toBeNull()
        ->and($garbled->fresh()->push_token)->toBeNull()
        ->and($valid->fresh()->push_token)->toBe('valid-token');

    // The next push only goes to the device left.
    app(NotificationCenter::class)->dispatch(new PushNotice, $this->user);

    expect(array_slice(pushedTokens(), 3))->toBe(['valid-token']);
});

test('a failing device does not fail the others', function () {
    FakeFcm::install(['busy-token' => 'UNAVAILABLE']);

    $busy = Device::factory()->for($this->user)->create(['push_token' => 'busy-token', 'last_used_at' => null]);
    $valid = Device::factory()->for($this->user)->create(['push_token' => 'valid-token', 'last_used_at' => null]);
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (FcmException $exception) use (&$reported): bool {
        $reported[] = $exception->errorCode;

        return false;
    });

    $sent = app(PushChannel::class)->send($this->user, new PushNotice);

    // A temporary failure keeps the token and is reported, not thrown; only
    // the device that got the push counts as used.
    expect($sent)->toBe(1)
        ->and(pushedTokens())->toBe(['busy-token', 'valid-token'])
        ->and($busy->fresh()->push_token)->toBe('busy-token')
        ->and($busy->fresh()->last_used_at)->toBeNull()
        ->and($valid->fresh()->last_used_at)->not->toBeNull()
        ->and($reported)->toBe(['UNAVAILABLE']);
});

test('push fails the job when no device could get it', function () {
    FakeFcm::install(['busy-token' => 'UNAVAILABLE']);

    Device::factory()->for($this->user)->create(['push_token' => 'busy-token']);

    // Nothing went out, so a retry sends nothing twice.
    expect(fn () => app(PushChannel::class)->send($this->user, new PushNotice))->toThrow(FcmException::class);
});

test('push payload carries the deep link', function () {
    Device::factory()->for($this->user)->create(['push_token' => 'phone-token']);

    app(NotificationCenter::class)->dispatch(new PushNotice, $this->user);

    Http::assertSent(fn (Request $request): bool => FakeFcm::isSend($request)
        && $request['message']['data'] === ['deep_link' => 'https://jarvis.example/home']);

    // Without an action, there is no deep link.
    $push = NotificationTypeChannel::query()
        ->where('notification_type_id', NotificationType::query()->where('slug', PushNotice::key())->value('id'))
        ->where('notification_channel_id', NotificationChannel::idFor(NotificationChannel::PUSH))
        ->sole();
    $push->templates->each->update(['actions' => []]);
    app()->forgetScopedInstances();

    expect((new PushNotice)->toPush($this->user)->data())->toBe([]);
});

test('push respects user preference', function () {
    Device::factory()->for($this->user)->create(['push_token' => 'phone-token']);
    $push = NotificationTypeChannel::query()
        ->where('notification_type_id', NotificationType::query()->where('slug', PushNotice::key())->value('id'))
        ->where('notification_channel_id', NotificationChannel::idFor(NotificationChannel::PUSH))
        ->sole();

    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'notification_type_id' => $push->notification_type_id,
        'notification_channel_id' => $push->notification_channel_id,
        'is_enabled' => false,
    ]);

    app(NotificationCenter::class)->dispatch(new PushNotice, $this->user);

    expect(pushedTokens())->toBe([])
        ->and($this->user->notifications()->count())->toBe(1);
});
