<?php

use App\Actions\Whatsapp\RenewWhatsappQrCode;
use App\Livewire\Whatsapp\AccountList;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Work']);
});

test('the owner sets a proxy per number, the connection restarts through it, and can go back to direct', function () {
    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->call('startProxy', $this->account->friendly_id)
        ->assertSet('showProxy', true)
        ->set('proxy.enabled', true)
        ->set('proxy.host', '100.64.0.2')
        ->set('proxy.port', '1080')
        ->set('proxy.username', 'jarvis')
        ->set('proxy.password', 'secret')
        ->call('saveProxy')
        ->assertHasNoErrors()
        ->assertSet('showProxy', false)
        ->assertSeeHtml('data-account-proxy-badge="'.$this->account->friendly_id.'"')
        ->assertSee('100.64.0.2:1080');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/proxy/set/'.$this->account->instance_name)
        && $request['enabled'] === true
        && $request['protocol'] === 'socks5'
        && $request['host'] === '100.64.0.2'
        && $request['port'] === '1080'
        && $request['password'] === 'secret');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/instance/restart/'));

    $account = $this->account->fresh();
    expect($account->proxy())->toBe(['protocol' => 'socks5', 'host' => '100.64.0.2', 'port' => 1080, 'username' => 'jarvis', 'password' => 'secret'])
        ->and($account->getRawOriginal('proxy_password'))->not->toBe('secret')
        ->and($account->toArray())->not->toHaveKey('proxy_password');

    // Saved again with the password blank, the saved one is kept.
    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->call('startProxy', $this->account->friendly_id)
        ->assertSet('proxy.password', '')
        ->set('proxy.port', '1081')
        ->call('saveProxy')
        ->assertHasNoErrors();

    expect($account->fresh()->proxy_password)->toBe('secret');

    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->call('startProxy', $this->account->friendly_id)
        ->set('proxy.enabled', false)
        ->call('saveProxy')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-account-proxy-badge=');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/proxy/set/') && $request['enabled'] === false);
    expect($account->fresh()->hasProxy())->toBeFalse();
});

test('a proxy that is invalid or that WhatsApp refuses is not saved', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->call('startProxy', $this->account->friendly_id)
        ->set('proxy.enabled', true)
        ->set('proxy.protocol', 'ftp')
        ->set('proxy.host', 'bad host')
        ->set('proxy.port', '70000')
        ->call('saveProxy')
        ->assertHasErrors(['proxy.protocol', 'proxy.host', 'proxy.port']);

    Http::assertNothingSent();

    FakeEvolutionApi::install(['setProxy' => Http::response(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => ['Invalid proxy']]], 400)]);

    $component->set('proxy.protocol', 'socks5')
        ->set('proxy.host', '100.64.0.2')
        ->set('proxy.port', '1080')
        ->call('saveProxy')
        ->assertHasErrors(['proxy.host'])
        ->assertSet('showProxy', true);

    expect($this->account->fresh()->hasProxy())->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/instance/restart/'));
});

test('only the owner sets the proxy of a number', function () {
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();

    Livewire::actingAs($intruder)
        ->test(AccountList::class)
        ->call('startProxy', $this->account->friendly_id)
        ->assertForbidden();
});

test('an instance created again keeps the proxy of its number', function () {
    $account = WhatsappAccount::factory()->closed()->for($this->owner)->create([
        'proxy_protocol' => 'socks5', 'proxy_host' => '100.64.0.2', 'proxy_port' => 1080,
    ]);
    FakeEvolutionApi::install(['connect' => Http::response(['status' => 404, 'error' => 'Not Found'], 404)]);

    app(RenewWhatsappQrCode::class)($this->owner, $account);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/proxy/set/'.$account->instance_name)
        && $request['host'] === '100.64.0.2');
});
