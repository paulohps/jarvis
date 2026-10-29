<?php

use App\Actions\Notifications\SendWhatsappNotificationCode;
use App\Actions\Notifications\UpdateNotificationTypeChannel;
use App\Actions\Notifications\UpdateWhatsappNotificationSettings;
use App\Actions\Notifications\VerifyWhatsappNotificationPhone;
use App\Livewire\Admin\Notifications\NotificationTypeList;
use App\Livewire\Profile\ProfilePage;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationGate;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\ModuleEnabled;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\Notifications\WhatsappNotice;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['notifications.types' => [WhatsappNotice::class]]);
    app(NotificationProvisioner::class)->provisionAll();

    FakeEvolutionApi::install();

    $this->user = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->user)->create(['nickname' => 'Personal']);
});

test('whatsapp channel sends through the users own account', function () {
    app(UpdateWhatsappNotificationSettings::class)($this->user, [
        'account' => $this->account->friendly_id,
        'phone' => '+55 11 95555-4444',
    ]);

    expect($this->user->refresh()->whatsapp_notification_phone)->toBe('+5511955554444')
        ->and(app(NotificationGate::class)->channelsFor(new WhatsappNotice, $this->user))->toBe([NotificationChannel::DATABASE, NotificationChannel::WHATSAPP]);

    app(NotificationCenter::class)->dispatch(new WhatsappNotice('the boiler'), $this->user);

    $delivery = WhatsappDelivery::query()->sole();

    expect($delivery->whatsapp_account_id)->toBe($this->account->id)
        ->and($delivery->source->slug)->toBe(WhatsappDeliverySource::NOTIFICATION)
        ->and($delivery->status->slug)->toBe(DeliveryStatus::SENT)
        ->and($delivery->target->jid)->toBe('5511955554444@s.whatsapp.net')
        ->and($delivery->target->is_synced)->toBeFalse()
        // Plain text: the title in bold, the paragraphs, then the action.
        ->and($delivery->body)->toBe("*Check the boiler*\n\nTime to check the boiler & the pipes.\n\nThanks!\n\nOpen: https://jarvis.example/home");

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendText/'.rawurlencode($this->account->instance_name))
        && $request['number'] === '5511955554444@s.whatsapp.net');

    // The in-app copy goes out as well.
    expect($this->user->notifications()->count())->toBe(1);
});

test('whatsapp channel is skipped without settings', function () {
    $gate = app(NotificationGate::class);

    expect($gate->channelsFor(new WhatsappNotice, $this->user))->toBe([NotificationChannel::DATABASE]);

    // A number without an account is not enough either.
    $this->user->forceFill(['whatsapp_notification_phone' => '+5511955554444'])->save();
    $gate->forget($this->user);

    expect($gate->channelsFor(new WhatsappNotice, $this->user))->toBe([NotificationChannel::DATABASE]);

    app(NotificationCenter::class)->dispatch(new WhatsappNotice, $this->user);

    expect(WhatsappDelivery::query()->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(1);
    Http::assertNothingSent();

    // Clearing the settings takes the channel away again.
    app(UpdateWhatsappNotificationSettings::class)($this->user, ['account' => $this->account->friendly_id, 'phone' => '+5511955554444']);
    expect($gate->channelsFor(new WhatsappNotice, $this->user->refresh()))->toContain(NotificationChannel::WHATSAPP);

    app(UpdateWhatsappNotificationSettings::class)($this->user, ['account' => '', 'phone' => '']);

    expect($this->user->refresh()->whatsapp_notification_account_id)->toBeNull()
        ->and($this->user->whatsapp_notification_phone)->toBeNull()
        ->and($gate->channelsFor(new WhatsappNotice, $this->user))->toBe([NotificationChannel::DATABASE]);
});

test('notification account must belong to the user', function () {
    $someoneElse = WhatsappAccount::factory()->open()->create();

    $errors = fn (array $input): array => rescue(
        fn () => app(UpdateWhatsappNotificationSettings::class)($this->user, $input) && [],
        fn (Throwable $exception) => $exception instanceof ValidationException ? $exception->errors() : throw $exception,
        report: false,
    );

    expect($errors(['account' => $someoneElse->friendly_id, 'phone' => '+5511955554444']))->toHaveKey('account')
        ->and($errors(['account' => $this->account->friendly_id, 'phone' => '11955554444']))->toHaveKey('phone')
        ->and($errors(['account' => $this->account->friendly_id, 'phone' => '']))->toHaveKey('phone')
        ->and($errors(['account' => '', 'phone' => '+5511955554444']))->toHaveKey('account')
        ->and($this->user->refresh()->whatsapp_notification_account_id)->toBeNull();

    // An account that changed hands since is not used by the gate nor the channel.
    $this->user->forceFill(['whatsapp_notification_account_id' => $someoneElse->id, 'whatsapp_notification_phone' => '+5511955554444'])->save();

    expect(app(NotificationGate::class)->channelsFor(new WhatsappNotice, $this->user))->toBe([NotificationChannel::DATABASE]);

    // The profile saves its own accounts only.
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class, ['tab' => ProfilePage::TAB_NOTIFICATIONS])
        ->assertSeeHtml('data-whatsapp-notification-settings')
        ->assertSee('Personal')
        ->set('whatsapp_notification_account', $someoneElse->friendly_id)
        ->set('whatsapp_notification_phone', '+5511955554444')
        ->call('saveWhatsappNotifications')
        ->assertHasErrors('account')
        ->set('whatsapp_notification_account', $this->account->friendly_id)
        ->call('saveWhatsappNotifications')
        ->assertHasNoErrors();

    expect($this->user->refresh()->whatsapp_notification_account_id)->toBe($this->account->id);
});

test('module enabled notice goes out on whatsapp with the module link', function () {
    config(['notifications.types' => [ModuleEnabled::class]]);
    app(NotificationProvisioner::class)->provisionAll();

    app(UpdateWhatsappNotificationSettings::class)($this->user, [
        'account' => $this->account->friendly_id,
        'phone' => '+55 11 95555-4444',
    ]);

    $module = Module::query()->where('slug', Module::WHATSAPP)->sole();

    app(NotificationCenter::class)->dispatch(new ModuleEnabled($module), $this->user);

    expect(WhatsappDelivery::query()->sole()->body)
        ->toContain($this->user->name)
        ->toContain(route('whatsapp.index'));
});

test('whatsapp notifications go from the platform account when the user picked none of their own', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $platformAccount = WhatsappAccount::factory()->open()->for($superAdmin)->create(['nickname' => 'Jarvis', 'phone_number' => '+5511900001111']);
    $whatsappChannel = NotificationChannel::query()->where('slug', NotificationChannel::WHATSAPP)->sole();

    // Only an account of a super admin may be picked, and only by a super admin.
    expect(fn () => app(UpdateNotificationTypeChannel::class)($superAdmin, $whatsappChannel, ['whatsapp_account' => $this->account->friendly_id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateNotificationTypeChannel::class)($this->user, $whatsappChannel, ['whatsapp_account' => $platformAccount->friendly_id]))
        ->toThrow(AuthorizationException::class);

    Livewire::actingAs($superAdmin)
        ->test(NotificationTypeList::class)
        ->assertSee('Jarvis')
        ->set('whatsappAccount', $platformAccount->friendly_id)
        ->assertHasNoErrors();

    expect($whatsappChannel->refresh()->whatsapp_account_id)->toBe($platformAccount->id);

    // A user without the module, and so without accounts, only picks the number.
    $user = User::factory()->create();

    $profile = Livewire::actingAs($user)
        ->test(ProfilePage::class, ['tab' => ProfilePage::TAB_NOTIFICATIONS])
        ->assertSeeHtml('data-whatsapp-notification-settings')
        ->assertSee('+5511900001111')
        ->set('whatsapp_notification_phone', '+5511900001111')
        ->call('saveWhatsappNotifications')
        // Never to the number they are sent from.
        ->assertHasErrors('phone')
        ->set('whatsapp_notification_phone', '+55 11 95555-4444')
        ->call('saveWhatsappNotifications')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-whatsapp-notification-verification');

    expect($user->refresh()->whatsapp_notification_account_id)->toBeNull()
        ->and($user->whatsapp_notification_phone)->toBe('+5511955554444')
        ->and($user->whatsapp_notification_phone_verified_at)->toBeNull();

    // Saving sent a code from the platform account; nothing else goes until it is typed.
    $codeDelivery = WhatsappDelivery::query()->sole();
    preg_match('/\b(\d{6})\b/', $codeDelivery->body, $matches);

    expect($codeDelivery->whatsapp_account_id)->toBe($platformAccount->id)
        ->and($codeDelivery->target->jid)->toBe('5511955554444@s.whatsapp.net')
        ->and(app(NotificationGate::class)->channelsFor(new WhatsappNotice, $user))->toBe([NotificationChannel::DATABASE]);

    $profile->set('whatsapp_notification_code', $matches[1])
        ->call('verifyWhatsappCode')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-whatsapp-notification-verification');

    expect($user->refresh()->whatsapp_notification_phone_verified_at)->not->toBeNull();

    app(NotificationCenter::class)->dispatch(new WhatsappNotice, $user);

    expect(WhatsappDelivery::query()->latest('id')->first())
        ->whatsapp_account_id->toBe($platformAccount->id)
        ->body->toContain('Check the boiler');

    // Keeping the number keeps it verified; another number needs a new code.
    app(UpdateWhatsappNotificationSettings::class)($user, ['phone' => '+5511955554444']);
    expect($user->refresh()->hasVerifiedWhatsappNotificationPhone())->toBeTrue();

    app(UpdateWhatsappNotificationSettings::class)($user, ['phone' => '+5511977778888']);
    expect($user->refresh()->hasVerifiedWhatsappNotificationPhone())->toBeFalse();
    app(UpdateWhatsappNotificationSettings::class)($user, ['phone' => '+5511955554444']);

    // The user's own account still wins over the platform's.
    app(UpdateWhatsappNotificationSettings::class)($this->user, ['account' => $this->account->friendly_id, 'phone' => '+5511955554444']);
    app(NotificationCenter::class)->dispatch(new WhatsappNotice, $this->user);

    expect(WhatsappDelivery::query()->latest('id')->first()->whatsapp_account_id)->toBe($this->account->id);

    // Once its owner is no longer a super admin, the platform account sends nothing.
    $user->forceFill(['whatsapp_notification_phone_verified_at' => now()])->save();
    $superAdmin->forceFill(['is_super_admin' => false])->save();
    app(NotificationGate::class)->forget($user);

    expect(app(NotificationGate::class)->channelsFor(new WhatsappNotice, $user->refresh()))->toBe([NotificationChannel::DATABASE]);
});

test('whatsapp notifications may go to the number of one of the users own accounts', function () {
    $this->account->update(['phone_number' => '+5511922223333']);
    $otherAccount = WhatsappAccount::factory()->open()->for($this->user)->create(['nickname' => 'Work', 'phone_number' => '+5511944445555']);
    $someoneElses = WhatsappAccount::factory()->open()->create(['phone_number' => '+5511966667777']);

    $errors = fn (array $input): array => rescue(
        fn () => app(UpdateWhatsappNotificationSettings::class)($this->user, $input) && [],
        fn (Throwable $exception) => $exception instanceof ValidationException ? $exception->errors() : throw $exception,
        report: false,
    );

    expect($errors(['account' => $this->account->friendly_id, 'to_account' => $someoneElses->friendly_id]))->toHaveKey('to_account')
        ->and($errors(['account' => $this->account->friendly_id, 'to_account' => $this->account->friendly_id]))->toHaveKey('phone');

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class, ['tab' => ProfilePage::TAB_NOTIFICATIONS])
        ->set('whatsapp_notification_account', $this->account->friendly_id)
        ->set('whatsapp_notification_to_account', $otherAccount->friendly_id)
        ->call('saveWhatsappNotifications')
        ->assertHasNoErrors()
        ->assertSet('whatsapp_notification_to_account', $otherAccount->friendly_id)
        ->assertSet('whatsapp_notification_phone', '');

    // A number the user connected is theirs already: no code to type.
    expect($this->user->refresh()->whatsapp_notification_phone)->toBe('+5511944445555')
        ->and($this->user->hasVerifiedWhatsappNotificationPhone())->toBeTrue();
});

test('the code that verifies a notification number fails safely', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $platformAccount = WhatsappAccount::factory()->open()->for($superAdmin)->create();
    NotificationChannel::query()->where('slug', NotificationChannel::WHATSAPP)->sole()->update(['whatsapp_account_id' => $platformAccount->id]);

    $user = User::factory()->create();
    app(UpdateWhatsappNotificationSettings::class)($user, ['phone' => '+5511955554444']);

    $errors = fn (Closure $callback): array => rescue(
        function () use ($callback): array {
            $callback();

            return [];
        },
        fn (Throwable $exception) => $exception instanceof ValidationException ? $exception->errors() : throw $exception,
        report: false,
    );
    $verify = fn (string $code): array => $errors(fn () => app(VerifyWhatsappNotificationPhone::class)($user, $code));
    $send = fn (): array => $errors(fn () => app(SendWhatsappNotificationCode::class)($user));

    // No code asked yet.
    expect($verify('123456'))->toHaveKey('code');

    expect($send())->toBe([]);
    preg_match('/\b(\d{6})\b/', WhatsappDelivery::query()->latest('id')->first()->body, $matches);
    $wrongCode = $matches[1] === '000000' ? '111111' : '000000';

    // Wrong codes lock the code after the last attempt, even the right one.
    foreach (range(1, VerifyWhatsappNotificationPhone::MAX_ATTEMPTS) as $attempt) {
        expect($verify($wrongCode))->toHaveKey('code');
    }

    expect($verify($matches[1]))->toHaveKey('code')
        ->and($user->refresh()->hasVerifiedWhatsappNotificationPhone())->toBeFalse();

    // A code only verifies the number it was sent to.
    expect($send())->toBe([]);
    preg_match('/\b(\d{6})\b/', WhatsappDelivery::query()->latest('id')->first()->body, $matches);
    $user->forceFill(['whatsapp_notification_phone' => '+5511977778888'])->save();

    expect($verify($matches[1]))->toHaveKey('code');

    // Codes are throttled per user, so the verification cannot flood a number.
    $user->forceFill(['whatsapp_notification_phone' => '+5511955554444'])->save();

    expect($send())->toBe([])
        ->and($send())->toHaveKey('code')
        ->and(WhatsappDelivery::query()->count())->toBe(SendWhatsappNotificationCode::MAX_CODES_PER_USER);

    // Without a platform account there is nothing to send the code from.
    $superAdmin->forceFill(['is_super_admin' => false])->save();
    RateLimiter::clear('whatsapp-notification-code:user:'.$user->id);

    expect($send())->toHaveKey('code');
});
