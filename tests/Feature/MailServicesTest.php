<?php

use App\Actions\Mail\SaveMailService;
use App\Actions\Mail\TestMailService;
use App\Http\Controllers\Mail\GmailConnectCallbackController;
use App\Http\Controllers\Mail\GmailConnectRedirectController;
use App\Livewire\Admin\Notifications\NotificationTypeList;
use App\Livewire\Mail\MailServiceList;
use App\Models\InvoiceRecurrence;
use App\Models\MailService;
use App\Models\MailServiceType;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Services\Mail\GmailTransport;
use App\Services\Mail\MailServiceMailer;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\ModuleEnabled;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['services.google.client_id' => 'web-client', 'services.google.client_secret' => 'web-secret']);

    $this->user = User::factory()->create();
    $this->admin = User::factory()->superAdmin()->create();
});

test('a user adds an smtp server with the password encrypted and masked', function () {
    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->set('name', 'Company SMTP')
        ->set('fromAddress', 'billing@example.com')
        ->set('fromName', 'Billing')
        ->set('host', 'smtp.example.com')
        ->set('port', '465')
        ->set('encryption', MailService::ENCRYPTION_SSL)
        ->set('username', 'billing')
        ->set('secret', 'super-secret-1234')
        ->set('isGlobal', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Company SMTP')
        ->assertSee('••••1234')
        ->assertDontSee('super-secret-1234');

    $service = MailService::query()->sole();

    expect($service->user_id)->toBe($this->user->id)
        ->and($service->isOfType(MailServiceType::SMTP))->toBeTrue()
        ->and($service->is_global)->toBeFalse()
        ->and($service->secret)->toBe('super-secret-1234')
        ->and(DB::table('mail_services')->value('secret'))->not->toContain('super-secret')
        ->and($service->toArray())->not->toHaveKey('secret')
        ->and(MailServiceMailer::configFor($service))->toMatchArray([
            'transport' => 'smtp',
            'scheme' => 'smtps',
            'host' => 'smtp.example.com',
            'port' => 465,
            'username' => 'billing',
            'password' => 'super-secret-1234',
            'from' => ['address' => 'billing@example.com', 'name' => 'Billing'],
        ]);
});

test('editing without a new secret keeps the stored one', function () {
    $service = MailService::factory()->resend()->for($this->user)->create(['secret' => 're_old_key_abcd']);

    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->call('edit', $service->friendly_id)
        ->assertSet('type', MailServiceType::RESEND)
        ->assertSet('secret', '')
        ->set('name', 'Resend (renamed)')
        ->call('save')
        ->assertHasNoErrors();

    expect($service->refresh()->name)->toBe('Resend (renamed)')
        ->and($service->secret)->toBe('re_old_key_abcd');
});

test('the form names the missing fields of each service', function () {
    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->set('type', MailServiceType::RESEND)
        ->set('name', 'Resend')
        ->call('save')
        ->assertHasErrors(['fromAddress', 'secret']);

    expect(fn () => app(SaveMailService::class)($this->user, ['type' => MailServiceType::GMAIL, 'name' => 'Gmail', 'from_address' => 'a@example.com']))
        ->toThrow(ValidationException::class);

    expect(MailService::query()->count())->toBe(0);
});

test('only the super admin makes a service global and others may send through it but not manage it', function () {
    $service = app(SaveMailService::class)($this->admin, [
        'type' => MailServiceType::RESEND,
        'name' => 'Family Resend',
        'from_address' => 'jarvis@example.com',
        'secret' => 're_family_key_9876',
        'is_global' => true,
    ]);

    expect($service->is_global)->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->assertSee('Family Resend')
        ->assertSee(__('Shared by :name', ['name' => $this->admin->name]))
        ->assertDontSee('••••9876')
        ->assertDontSeeHtml('data-mail-edit="'.$service->friendly_id.'"')
        ->call('edit', $service->friendly_id)
        ->assertForbidden();

    expect(fn () => app(SaveMailService::class)($this->user, ['name' => 'Mine now'], $service))->toThrow(AuthorizationException::class);

    // Someone else's private service is not even found.
    $private = MailService::factory()->for($this->admin)->create();

    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->assertDontSee($private->name)
        ->call('test', $private->friendly_id)
        ->assertNotFound();
});

test('the test e-mail goes through the service to whoever asked', function () {
    Mail::fake();
    $service = MailService::factory()->for($this->user)->create();

    $result = app(TestMailService::class)($this->user, $service);

    expect($result['successful'])->toBeTrue()
        ->and($result['message'])->toContain($this->user->email)
        ->and(config('mail.mailers.'.MailServiceMailer::NAME_PREFIX.$service->id.'.host'))->toBe('smtp.example.com');
});

test('a failing test shows the transport error', function () {
    Http::fake([GmailTransport::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
    $service = MailService::factory()->gmail()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->call('test', $service->friendly_id)
        ->assertSeeHtml('data-mail-test-result="failure"')
        ->assertSee(__('Google no longer accepts this account’s connection (revoked or expired). Connect the Google account again.'));
});

test('the gmail transport refreshes the token once and sends raw mime', function () {
    Http::fake([
        GmailTransport::TOKEN_URL => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
        GmailTransport::SEND_URL => Http::response(['id' => '18c0ffee', 'threadId' => '18c0ffee']),
    ]);
    $service = MailService::factory()->gmail()->for($this->user)->create(['from_name' => 'Ana']);

    $mailer = MailServiceMailer::for($service);
    $mailer->raw('First', fn ($message) => $message->to('ap@example.com')->bcc('hidden@example.com')->subject('Invoice 1'));
    $mailer->raw('Second', fn ($message) => $message->to('ap@example.com')->subject('Invoice 2'));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === GmailTransport::TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === $service->secret
        && $request['client_id'] === 'web-client');

    $sends = Http::recorded(fn (Request $request): bool => $request->url() === GmailTransport::SEND_URL)->values();
    $first = base64_decode(strtr($sends[0][0]['raw'], '-_', '+/'));

    expect($sends)->toHaveCount(2)
        ->and($sends[0][0]->hasHeader('Authorization', 'Bearer ya29.token'))->toBeTrue()
        ->and($first)->toContain('From: Ana <someone@gmail.com>')
        ->and($first)->toContain('Subject: Invoice 1')
        ->and($first)->toStartWith('Bcc: hidden@example.com');
});

test('gmail refusing the message fails the send', function () {
    Cache::flush();
    Http::fake([
        GmailTransport::TOKEN_URL => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
        GmailTransport::SEND_URL => Http::response(['error' => ['message' => 'Daily sending quota exceeded']], 429),
    ]);
    $service = MailService::factory()->gmail()->for($this->user)->create();

    expect(fn () => MailServiceMailer::for($service)->raw('Hi', fn ($message) => $message->to('ap@example.com')->subject('Hi')))
        ->toThrow(TransportException::class, 'Daily sending quota exceeded');
});

test('connecting a google account stores its refresh token', function () {
    Socialite::fake('google', (new SocialiteUser)->map(['id' => '42', 'email' => 'ana@gmail.com', 'name' => 'Ana'])
        ->setRefreshToken('1//refresh-token')
        ->setApprovedScopes(['openid', 'email', GmailTransport::SCOPE]));

    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->set('type', MailServiceType::GMAIL)
        ->set('name', 'My Gmail')
        ->call('save')
        ->assertRedirect(route('mail-services.google.redirect', ['name' => 'My Gmail']));

    $this->actingAs($this->user)
        ->get(route('mail-services.google.redirect', ['name' => 'My Gmail', 'is_global' => '1']))
        ->assertRedirect('https://socialite.fake/google/authorize')
        ->assertSessionHas(GmailConnectRedirectController::SESSION_KEY.'.name', 'My Gmail');

    $this->actingAs($this->user)
        ->get(route('mail-services.google.callback', ['code' => 'the-code', 'state' => 'x']))
        ->assertRedirect(route('mail-services.index'))
        ->assertSessionHas(GmailConnectCallbackController::STATUS_KEY);

    $service = MailService::query()->sole();

    expect($service->isOfType(MailServiceType::GMAIL))->toBeTrue()
        ->and($service->name)->toBe('My Gmail')
        ->and($service->from_address)->toBe('ana@gmail.com')
        ->and($service->from_name)->toBe($this->user->name)
        ->and($service->secret)->toBe('1//refresh-token')
        // Only the super admin makes one global.
        ->and($service->is_global)->toBeFalse();

    // Reconnecting keeps the same service.
    Socialite::fake('google', (new SocialiteUser)->map(['id' => '42', 'email' => 'ana@gmail.com'])
        ->setRefreshToken('1//new-token')
        ->setApprovedScopes([GmailTransport::SCOPE]));

    $this->actingAs($this->user)->get(route('mail-services.google.redirect', ['service' => $service->friendly_id]));
    $this->actingAs($this->user)->get(route('mail-services.google.callback', ['code' => 'c']));

    expect(MailService::query()->sole()->secret)->toBe('1//new-token');
});

test('a google account without the send permission is not stored', function () {
    Socialite::fake('google', (new SocialiteUser)->map(['id' => '42', 'email' => 'ana@gmail.com'])
        ->setRefreshToken('1//refresh-token')
        ->setApprovedScopes(['openid', 'email']));

    $this->actingAs($this->user)->get(route('mail-services.google.redirect'));
    $this->actingAs($this->user)
        ->get(route('mail-services.google.callback', ['code' => 'c']))
        ->assertRedirect(route('mail-services.index'))
        ->assertSessionHas(GmailConnectCallbackController::ERROR_KEY);

    // Cancelling on Google stores nothing either.
    $this->actingAs($this->user)->get(route('mail-services.google.redirect'));
    $this->actingAs($this->user)
        ->get(route('mail-services.google.callback', ['error' => 'access_denied']))
        ->assertSessionHas(GmailConnectCallbackController::ERROR_KEY);

    expect(MailService::query()->count())->toBe(0);
});

test('another users google service cannot be reconnected', function () {
    Socialite::fake('google');
    $service = MailService::factory()->gmail()->for($this->admin)->create();

    $this->actingAs($this->user)
        ->get(route('mail-services.google.redirect', ['service' => $service->friendly_id]))
        ->assertForbidden();
});

test('a notification type sends its e-mail through the global service the admin picked', function () {
    config(['notifications.types' => [ModuleEnabled::class]]);
    app(NotificationProvisioner::class)->provisionAll();

    $service = MailService::factory()->global()->for($this->admin)->create(['name' => 'Family SMTP']);

    Livewire::actingAs($this->admin)
        ->test(NotificationTypeList::class)
        ->assertSee('Family SMTP')
        ->set('mailService.'.NotificationType::MODULE_ENABLED, $service->friendly_id)
        ->assertSet('mailService.'.NotificationType::MODULE_ENABLED, $service->friendly_id);

    $module = Module::query()->where('slug', Module::WHATSAPP)->sole();

    expect((new ModuleEnabled($module))->toMail($this->user)->mailer)->toBe(MailServiceMailer::NAME_PREFIX.$service->id);
});

test('a notification type sends its e-mail through a super admins own service, never another users', function () {
    config(['notifications.types' => [ModuleEnabled::class]]);
    app(NotificationProvisioner::class)->provisionAll();

    $adminService = MailService::factory()->gmail()->for($this->admin)->create(['name' => 'Admin Gmail']);
    $userService = MailService::factory()->for($this->user)->create(['name' => 'User SMTP']);

    Livewire::actingAs($this->admin)
        ->test(NotificationTypeList::class)
        ->assertSee('Admin Gmail')
        ->assertDontSee('User SMTP')
        ->set('mailService.'.NotificationType::MODULE_ENABLED, $userService->friendly_id)
        ->assertHasErrors()
        ->set('mailService.'.NotificationType::MODULE_ENABLED, $adminService->friendly_id)
        ->assertSet('mailService.'.NotificationType::MODULE_ENABLED, $adminService->friendly_id);

    $module = Module::query()->where('slug', Module::WHATSAPP)->sole();

    expect((new ModuleEnabled($module))->toMail($this->user)->mailer)->toBe(MailServiceMailer::NAME_PREFIX.$adminService->id);

    // Its owner no longer a super admin: back to the default mailer.
    $this->admin->forceFill(['is_super_admin' => false])->save();

    expect((new ModuleEnabled($module))->toMail($this->user)->mailer)->toBeNull();
});

test('a super admins service that stops being global keeps sending the notifications', function () {
    config(['notifications.types' => [ModuleEnabled::class]]);
    app(NotificationProvisioner::class)->provisionAll();

    $service = MailService::factory()->global()->for($this->admin)->create();
    $typeChannel = NotificationTypeChannel::query()
        ->where('notification_channel_id', NotificationChannel::idFor(NotificationChannel::MAIL))
        ->sole();
    $typeChannel->update(['mail_service_id' => $service->id]);

    app(SaveMailService::class)($this->admin, ['name' => $service->name, 'from_address' => $service->from_address, 'host' => 'smtp.example.com', 'port' => 587, 'is_global' => false], $service);

    expect($typeChannel->refresh()->mail_service_id)->toBe($service->id);
});

test('a service that stops being global is released from other users recurrences', function () {
    $service = MailService::factory()->global()->for($this->admin)->create();
    $others = InvoiceRecurrence::factory()->for($this->user, 'createdBy')->create(['mail_service_id' => $service->id]);
    $own = InvoiceRecurrence::factory()->for($this->admin, 'createdBy')->create(['mail_service_id' => $service->id]);

    app(SaveMailService::class)($this->admin, ['name' => $service->name, 'from_address' => $service->from_address, 'host' => 'smtp.example.com', 'port' => 587, 'is_global' => false], $service);

    expect($others->refresh()->mail_service_id)->toBeNull()
        ->and($own->refresh()->mail_service_id)->toBe($service->id);
});

test('removing a service sends its picks back to the default mailer', function () {
    $service = MailService::factory()->for($this->user)->create();
    $recurrence = InvoiceRecurrence::factory()->for($this->user, 'createdBy')->create(['mail_service_id' => $service->id]);

    Livewire::actingAs($this->user)
        ->test(MailServiceList::class)
        ->call('remove', $service->friendly_id)
        ->assertSee(__('Mail service :name removed.', ['name' => $service->name]));

    expect(MailService::query()->count())->toBe(0)
        ->and($recurrence->refresh()->mail_service_id)->toBeNull();
});

test('the profile and the admin area link to the mail services', function () {
    $this->actingAs($this->user)->get(route('mail-services.index'))->assertOk()->assertSee(__('Add a mail service'));
    $this->actingAs($this->user)->get(route('profile', ['tab' => 'preferences']))->assertSeeHtml('data-profile-mail-services');
    $this->actingAs($this->admin)->get(route('admin.index'))->assertSeeHtml('data-admin-section="mail-services.index"');
});
