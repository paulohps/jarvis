<?php

use App\Actions\Whatsapp\ConnectWhatsappAccount;
use App\Actions\Whatsapp\RequestWhatsappPairingCode;
use App\Livewire\Whatsapp\ConnectAccount;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    FakeEvolutionApi::install();

    $this->user = User::factory()->withModules([Module::WHATSAPP])->create();
});

test('connecting creates an instance and a connecting account', function () {
    $attempt = app(ConnectWhatsappAccount::class)($this->user, '  Personal  ');
    $account = $attempt->account;

    expect($account->user_id)->toBe($this->user->id)
        ->and($account->nickname)->toBe('Personal')
        ->and($account->instance_name)->toStartWith(ConnectWhatsappAccount::INSTANCE_PREFIX)
        ->and($account->status->slug)->toBe(WhatsappConnectionStatus::CONNECTING)
        ->and($account->phone_number)->toBeNull()
        ->and($account->connected_at)->toBeNull()
        ->and($attempt->qrCode->base64)->toBe(FakeEvolutionApi::CREATED_QR);

    Http::assertSent(fn (Request $request): bool => $request->url() === config('services.evolution.url').'/instance/create'
        && $request['instanceName'] === $account->instance_name);

    // N accounts per user, each with its own instance.
    $second = app(ConnectWhatsappAccount::class)($this->user, 'Work')->account;

    expect($second->instance_name)->not->toBe($account->instance_name)
        ->and($this->user->whatsappAccounts()->count())->toBe(2);

    // A nickname is required.
    expect(fn () => app(ConnectWhatsappAccount::class)($this->user, ' '))->toThrow(ValidationException::class);

    // The screen: the QR code shows, swaps without a refresh and gives way to `open`.
    $component = Livewire::actingAs($this->user)
        ->test(ConnectAccount::class)
        ->assertSee(__('Show QR code'))
        ->set('nickname', 'Home')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSet('status', WhatsappConnectionStatus::CONNECTING)
        ->assertSet('qrCode', FakeEvolutionApi::CREATED_QR)
        ->assertSeeHtml('data-qr-code');

    $home = $this->user->whatsappAccounts()->where('nickname', 'Home')->sole();

    expect($component->get('accountId'))->toBe($home->friendly_id);

    $component
        // Another account's QR code is not this one.
        ->call('qrCodeUpdated', ['account_id' => $account->friendly_id, 'qr_code' => 'data:image/png;base64,T1RIRVI='])
        ->assertSet('qrCode', FakeEvolutionApi::CREATED_QR)
        ->call('qrCodeUpdated', ['account_id' => $home->friendly_id, 'qr_code' => 'data:image/png;base64,TkVX'])
        ->assertSet('qrCode', 'data:image/png;base64,TkVX')
        // Renewal while the screen stays open.
        ->call('renew')
        ->assertSet('qrCode', FakeEvolutionApi::RENEWED_QR);

    $home->update(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::OPEN)]);

    $component->call('statusChanged', ['account_id' => $home->friendly_id, 'status' => WhatsappConnectionStatus::OPEN])
        ->assertSet('status', WhatsappConnectionStatus::OPEN)
        ->assertSet('qrCode', null)
        ->assertSeeHtml('data-connect-state="open"')
        ->assertSee(__(':nickname is connected', ['nickname' => 'Home']));
});

test('reconnecting a closed account shows a fresh qr code', function () {
    $account = WhatsappAccount::factory()->closed()->for($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('whatsapp.accounts.reconnect', $account))
        ->assertOk()
        ->assertSee(FakeEvolutionApi::RENEWED_QR, false);

    expect($account->fresh()->status->slug)->toBe(WhatsappConnectionStatus::CONNECTING);

    // An instance gone from the Evolution API is created again under the same name.
    FakeEvolutionApi::install(['connect' => Http::response(['status' => 404, 'error' => 'Not Found'], 404)]);

    Livewire::actingAs($this->user)
        ->test(ConnectAccount::class, ['account' => $account])
        ->assertSet('qrCode', FakeEvolutionApi::CREATED_QR);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/instance/create')
        && $request['instanceName'] === $account->instance_name);
});

test('users without whatsapp module cannot connect accounts', function () {
    $outsider = User::factory()->withModules([Module::INVOICES])->create();

    expect(fn () => app(ConnectWhatsappAccount::class)($outsider, 'Personal'))->toThrow(AuthorizationException::class);

    $this->actingAs($outsider)->get(route('whatsapp.accounts.connect'))->assertForbidden();

    // An open screen of someone who lost the module is refused too.
    $this->user->memberships->each(fn ($membership) => $membership->modules()->detach());
    $this->user->forgetMemberships();

    Livewire::actingAs($this->user->fresh())
        ->test(ConnectAccount::class)
        ->set('nickname', 'Personal')
        ->call('connect')
        ->assertForbidden();

    expect(WhatsappAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a new number can be linked by pairing code', function () {
    $attempt = app(ConnectWhatsappAccount::class)($this->user, 'Personal', '+55 (11) 99999-0000');

    expect($attempt->qrCode->pairingCode)->toBe(FakeEvolutionApi::PAIRING_CODE)
        ->and($attempt->account->status->slug)->toBe(WhatsappConnectionStatus::CONNECTING);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/instance/create')
        && $request['instanceName'] === $attempt->account->instance_name
        && $request['number'] === '5511999990000');

    // The number needs the country code and area code.
    expect(fn () => app(ConnectWhatsappAccount::class)($this->user, 'Work', '99999-0000'))->toThrow(ValidationException::class);

    // The screen: the code shows instead of the QR code and follows the webhook.
    $component = Livewire::actingAs($this->user)
        ->test(ConnectAccount::class)
        ->assertSee(__('Link with phone number instead'))
        ->set('linkByCode', true)
        ->set('nickname', 'Home')
        ->set('phoneNumber', '123')
        ->call('connect')
        ->assertHasErrors('phoneNumber')
        ->assertSet('accountId', null)
        ->set('phoneNumber', '+55 11 98888-0000')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSet('pairingNumber', '5511988880000')
        ->assertSet('pairingCode', FakeEvolutionApi::PAIRING_CODE)
        ->assertSee('WZYE-H1YY')
        ->assertDontSeeHtml('data-qr-code ');

    $home = $this->user->whatsappAccounts()->where('nickname', 'Home')->sole();

    $component
        ->call('qrCodeUpdated', ['account_id' => $home->friendly_id, 'qr_code' => 'data:image/png;base64,TkVX', 'pairing_code' => 'ABCD1234'])
        ->assertSee('ABCD-1234')
        // The renewal keeps the instance handing out pairing codes.
        ->call('renew');

    Http::assertSent(fn (Request $request): bool => $request->url() === config('services.evolution.url').'/instance/connect/'.$home->instance_name.'?number=5511988880000');

    // Back to the QR code.
    $component->call('useQrCode')
        ->assertSet('linkByCode', false)
        ->assertSeeHtml('data-qr-code');
});

test('an account waiting for its qr code can switch to a pairing code', function () {
    $account = WhatsappAccount::factory()->closed()->for($this->user)->create();
    $oldInstanceName = $account->instance_name;

    $component = Livewire::actingAs($this->user)
        ->test(ConnectAccount::class, ['account' => $account])
        ->assertSeeHtml('data-qr-code')
        ->set('linkByCode', true)
        ->set('phoneNumber', '+55 11 99999-0000')
        ->call('requestPairingCode')
        ->assertHasNoErrors()
        ->assertSet('pairingCode', FakeEvolutionApi::PAIRING_CODE)
        ->assertSee('WZYE-H1YY');

    $account->refresh();

    // A new instance started with the number; the old one is gone.
    expect($account->instance_name)->not->toBe($oldInstanceName)
        ->and($account->instance_name)->toStartWith(ConnectWhatsappAccount::INSTANCE_PREFIX)
        ->and($account->status->slug)->toBe(WhatsappConnectionStatus::CONNECTING);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/instance/create')
        && $request['instanceName'] === $account->instance_name
        && $request['number'] === '5511999990000');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/instance/delete/'.$oldInstanceName));

    // An old instance already gone from the Evolution API changes nothing.
    FakeEvolutionApi::install(['deleteInstance' => Http::response(['status' => 404, 'error' => 'Not Found'], 404)]);

    $component->call('requestPairingCode')
        ->assertHasNoErrors()
        ->assertSet('error', null)
        ->assertSet('pairingCode', FakeEvolutionApi::PAIRING_CODE);

    // Someone else's account is refused.
    $other = WhatsappAccount::factory()->connecting()->create();

    expect(fn () => app(RequestWhatsappPairingCode::class)($this->user, $other, '+5511999990000'))->toThrow(AuthorizationException::class);
});
