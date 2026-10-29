<?php

use App\Actions\Whatsapp\RefreshWhatsappConnectionStatus;
use App\Actions\Whatsapp\RemoveWhatsappAccount;
use App\Actions\Whatsapp\RenameWhatsappAccount;
use App\Actions\Whatsapp\RenewWhatsappQrCode;
use App\Actions\Whatsapp\RestartWhatsappConnection;
use App\Actions\Whatsapp\ResyncWhatsappTargets;
use App\Jobs\SyncWhatsappTargets;
use App\Livewire\Whatsapp\AccountList;
use App\Models\Module;
use App\Models\Recurrence;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
});

test('accounts are visible only to their owner', function () {
    $account = WhatsappAccount::factory()->closed()->for($this->owner)->create(['nickname' => 'Personal phone']);
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();
    $admin = User::factory()->superAdmin()->create();
    WhatsappAccount::factory()->open()->for($intruder)->create(['nickname' => 'Intruder phone']);

    foreach (['view', 'use', 'delete'] as $ability) {
        expect(Gate::forUser($this->owner)->allows($ability, $account))->toBeTrue()
            ->and(Gate::forUser($intruder)->allows($ability, $account))->toBeFalse()
            ->and(Gate::forUser($admin)->allows($ability, $account))->toBeFalse();
    }

    // The owner's list shows their accounts with the live status, and nobody else's.
    $this->actingAs($this->owner)->get(route('whatsapp.index'))->assertRedirect(route('whatsapp.accounts.index'));

    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->assertSee('Personal phone')
        ->assertSeeHtml('data-account-state="closed"')
        ->assertSeeHtml('data-account-reconnect="'.$account->friendly_id.'"')
        ->assertDontSee('Intruder phone')
        ->tap(fn () => $account->update(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::OPEN)]))
        ->call('refreshAccounts')
        ->assertSeeHtml('data-account-state="open"')
        ->assertDontSeeHtml('data-account-reconnect="'.$account->friendly_id.'"');

    Livewire::actingAs($admin)->test(AccountList::class)->assertDontSee('Personal phone')->assertDontSee('Intruder phone');

    // Anyone else neither reconnects it nor removes it: 403.
    $this->actingAs($intruder)->get(route('whatsapp.accounts.reconnect', $account))->assertForbidden();
    $this->actingAs($admin)->get(route('whatsapp.accounts.reconnect', $account))->assertForbidden();

    expect(fn () => app(RenewWhatsappQrCode::class)($intruder, $account))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveWhatsappAccount::class)($intruder, $account))->toThrow(AuthorizationException::class);

    Livewire::actingAs($intruder)->test(AccountList::class)->call('remove', $account->friendly_id)->assertForbidden();

    expect($account->fresh())->not->toBeNull();
    Http::assertNothingSent();

    // Users without the module do not reach the list at all.
    $outsider = User::factory()->withModules([Module::INVOICES])->create();
    $this->actingAs($outsider)->get(route('whatsapp.accounts.index'))->assertForbidden();
});

test('removing an account pauses its scheduled messages', function () {
    $account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Work']);
    $keptAccount = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->owner->update(['whatsapp_notification_account_id' => $account->id]);

    $recurring = WhatsappMessage::factory()->recurring()->for($this->owner)->for($account, 'account')->create();
    $once = WhatsappMessage::factory()->once()->for($this->owner)->for($account, 'account')->create();
    $kept = WhatsappMessage::factory()->recurring()->for($this->owner)->for($keptAccount, 'account')->create();
    WhatsappTarget::factory()->for($account, 'account')->count(2)->create();

    $recurrenceIds = [$recurring->recurrence->id, $once->recurrence->id];

    // While the number is logged out and the instance deleted, nothing of it may run any more.
    $pausedFirst = function () use ($recurrenceIds) {
        expect(Recurrence::query()->whereKey($recurrenceIds)->where('is_paused', false)->exists())->toBeFalse();

        return Http::response(['status' => 'SUCCESS']);
    };

    FakeEvolutionApi::install(['logout' => $pausedFirst, 'deleteInstance' => $pausedFirst]);

    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->call('remove', $account->friendly_id)
        ->assertSee(__(':nickname was removed.', ['nickname' => 'Work']))
        ->assertDontSeeHtml('data-whatsapp-account="'.$account->friendly_id.'"');

    expect(Recurrence::query()->whereKey($recurrenceIds)->pluck('is_paused')->all())->toBe([true, true])
        ->and($kept->recurrence->fresh()->is_paused)->toBeFalse()
        ->and(WhatsappAccount::query()->find($account->id))->toBeNull()
        ->and(WhatsappMessage::withTrashed()->where('whatsapp_account_id', $account->id)->exists())->toBeFalse()
        ->and(WhatsappTarget::query()->where('whatsapp_account_id', $account->id)->exists())->toBeFalse()
        ->and($this->owner->fresh()->whatsapp_notification_account_id)->toBeNull()
        ->and($keptAccount->fresh())->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/instance/logout/'.$account->instance_name));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/instance/delete/'.$account->instance_name));

    // A number already logged out (400) or an instance already gone (404) does not stop the removal.
    $loggedOut = WhatsappAccount::factory()->closed()->for($this->owner)->create();

    FakeEvolutionApi::install([
        'logout' => Http::response(['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => ['The instance is not connected']]], 400),
        'deleteInstance' => Http::response(['status' => 404, 'error' => 'Not Found'], 404),
    ]);

    app(RemoveWhatsappAccount::class)($this->owner, $loggedOut);

    expect($loggedOut->fresh())->toBeNull();

    // The Evolution API down keeps the account, and says so.
    $unreachable = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Home']);

    FakeEvolutionApi::install(['logout' => Http::response(['status' => 500], 500)]);

    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->call('remove', $unreachable->friendly_id)
        ->assertSeeHtml('data-account-error');

    expect($unreachable->fresh())->not->toBeNull();
});

test('owners rename, resync, restart and check their accounts', function () {
    Queue::fake([SyncWhatsappTargets::class]);

    $account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);

    $component = Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->assertSeeHtml('data-account-resync="'.$account->friendly_id.'"')
        ->assertSeeHtml('data-account-restart="'.$account->friendly_id.'"');

    // Rename: required, then saved.
    $component->call('startRename', $account->friendly_id)
        ->assertSet('nickname', 'Personal')
        ->set('nickname', ' ')
        ->call('rename')
        ->assertHasErrors('nickname')
        ->set('nickname', '  Work  ')
        ->call('rename')
        ->assertHasNoErrors()
        ->assertSet('showRename', false)
        ->assertSee('Work');

    expect($account->fresh()->nickname)->toBe('Work');

    // Resync queues one sync for the account.
    $component->call('resync', $account->friendly_id)->assertSeeHtml('data-account-status');

    Queue::assertPushed(SyncWhatsappTargets::class, fn (SyncWhatsappTargets $job): bool => $job->account->is($account));

    // Restart reopens the connection without unlinking the number.
    $component->call('restart', $account->friendly_id)->assertSeeHtml('data-account-status');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/instance/restart/'.$account->instance_name));
    expect($account->fresh()->isOpen())->toBeTrue();

    // Check connection: the instance is gone, so the account is closed.
    Notification::fake();
    FakeEvolutionApi::install(['connectionState' => ['instance' => ['instanceName' => $account->instance_name, 'state' => null]]]);

    $component->call('checkConnection', $account->friendly_id)
        ->assertSee(__(':nickname is not connected. Reconnect it to use it again.', ['nickname' => 'Work']))
        ->assertSeeHtml('data-account-state="closed"');

    expect($account->fresh()->status->slug)->toBe(WhatsappConnectionStatus::CLOSED)
        ->and($account->fresh()->disconnected_at)->not->toBeNull();

    // Not connected: no sync, no restart (nothing reaches the Evolution API).
    Queue::fake([SyncWhatsappTargets::class]);
    FakeEvolutionApi::install();

    $component->call('resync', $account->friendly_id)->assertSeeHtml('data-account-error')
        ->call('restart', $account->friendly_id)->assertSeeHtml('data-account-error');

    Queue::assertNothingPushed();
    Http::assertNothingSent();

    // Back `open` in the Evolution API: checking brings the account back and syncs it.
    $component->call('checkConnection', $account->friendly_id)
        ->assertSee(__(':nickname is connected.', ['nickname' => 'Work']));

    expect($account->fresh()->isOpen())->toBeTrue();
    Queue::assertPushed(SyncWhatsappTargets::class);

    // Someone else's account is refused everywhere.
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();

    expect(fn () => app(RenameWhatsappAccount::class)($intruder, $account, 'Mine'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResyncWhatsappTargets::class)($intruder, $account))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestartWhatsappConnection::class)($intruder, $account))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RefreshWhatsappConnectionStatus::class)($intruder, $account))->toThrow(AuthorizationException::class);

    Livewire::actingAs($intruder)->test(AccountList::class)->call('startRename', $account->friendly_id)->assertForbidden();
});
