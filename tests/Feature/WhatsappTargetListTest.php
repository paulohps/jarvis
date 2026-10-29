<?php

use App\Actions\Whatsapp\AddWhatsappTarget;
use App\Actions\Whatsapp\RemoveWhatsappTarget;
use App\Jobs\SyncWhatsappTargets;
use App\Livewire\Whatsapp\TargetList;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
});

test('the owner sees the contacts and groups of an account', function () {
    WhatsappTarget::factory()->contact()->for($this->account, 'account')->create(['name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);
    WhatsappTarget::factory()->contact()->for($this->account, 'account')->create(['name' => 'Bruno', 'jid' => '5511922220000@s.whatsapp.net']);
    WhatsappTarget::factory()->group()->for($this->account, 'account')->create(['name' => 'Family']);
    WhatsappTarget::factory()->contact()->for(WhatsappAccount::factory()->open(), 'account')->create(['name' => 'Someone else']);

    $this->actingAs($this->owner)
        ->get(route('whatsapp.accounts.targets', $this->account))
        ->assertOk()
        ->assertSeeInOrder(['Ana', 'Bruno'])
        ->assertDontSee('Family')
        ->assertDontSee('Someone else');

    Livewire::actingAs($this->owner)
        ->test(TargetList::class, ['account' => $this->account])
        ->set('type', WhatsappTargetType::GROUP)
        ->assertSee('Family')
        ->assertDontSee('Ana')
        ->set('type', WhatsappTargetType::CONTACT)
        // Search by name or by digits of the number.
        ->set('search', 'bru')
        ->assertSee('Bruno')
        ->assertDontSee('Ana')
        ->set('search', '11 91111')
        ->assertSee('Ana')
        ->assertDontSee('Bruno');

    // Nobody else opens it.
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();

    $this->actingAs($intruder)->get(route('whatsapp.accounts.targets', $this->account))->assertForbidden();
});

test('contacts and groups can be added by hand, renamed and removed', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(TargetList::class, ['account' => $this->account])
        ->call('create')
        ->set('identifier', '123')
        ->call('save')
        ->assertHasErrors('identifier')
        ->set('identifier', '+55 (11) 98888-0000')
        ->set('name', 'Carla')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSee('Carla')
        ->assertSeeHtml('data-target-manual');

    $carla = $this->account->targets()->where('jid', '5511988880000@s.whatsapp.net')->sole();

    expect($carla->is_synced)->toBeFalse()
        ->and($carla->type->slug)->toBe(WhatsappTargetType::CONTACT);

    // The same number again is refused.
    $component->call('create')->set('identifier', '+5511988880000')->call('save')->assertHasErrors('identifier');

    // A group by its id, with or without `@g.us`.
    $component->set('type', WhatsappTargetType::GROUP)
        ->call('create')
        ->set('identifier', 'not a group')
        ->call('save')
        ->assertHasErrors('identifier')
        ->set('identifier', '120363000000000099@g.us')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->account->targets()->where('jid', '120363000000000099@g.us')->sole()->type->slug)->toBe(WhatsappTargetType::GROUP);

    // Rename it; the number stays.
    $component->set('type', WhatsappTargetType::CONTACT)
        ->call('edit', $carla->friendly_id)
        ->assertSet('identifier', '+5511988880000')
        ->set('name', 'Carla M.')
        ->call('save')
        ->assertHasNoErrors();

    expect($carla->fresh()->name)->toBe('Carla M.')
        ->and($carla->fresh()->jid)->toBe('5511988880000@s.whatsapp.net');

    // Used by a message: it stays.
    $message = WhatsappMessage::factory()->for($this->owner)->for($this->account, 'account')->create();
    $message->targets()->attach($carla);

    $component->call('remove', $carla->friendly_id)->assertSeeHtml('data-target-error');

    expect($carla->fresh())->not->toBeNull();

    // Unused: removed.
    $message->targets()->detach($carla);

    $component->call('remove', $carla->friendly_id)->assertSeeHtml('data-target-status');

    expect($carla->fresh())->toBeNull();
});

test('synced contacts cannot be renamed or removed and other accounts are refused', function () {
    $synced = WhatsappTarget::factory()->contact()->for($this->account, 'account')->create(['name' => 'Ana']);

    Livewire::actingAs($this->owner)
        ->test(TargetList::class, ['account' => $this->account])
        ->assertDontSeeHtml('data-target-edit="'.$synced->friendly_id.'"')
        ->assertDontSeeHtml('data-target-remove="'.$synced->friendly_id.'"')
        ->call('remove', $synced->friendly_id)
        ->assertSeeHtml('data-target-error');

    expect($synced->fresh())->not->toBeNull();

    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();

    expect(fn () => app(AddWhatsappTarget::class)($intruder, $this->account, WhatsappTargetType::CONTACT, '+5511977770000'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveWhatsappTarget::class)($intruder, $synced))->toThrow(AuthorizationException::class);
});

test('a group is added by its invite link, joining it', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(TargetList::class, ['account' => $this->account])
        ->set('type', WhatsappTargetType::GROUP)
        ->call('create')
        ->assertSet('joinGroup', true)
        ->set('identifier', 'https://chat.whatsapp.com/F1EX5QZxO181L3TMVP31gY')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Book club');

    $group = $this->account->targets()->where('jid', FakeEvolutionApi::INVITE_GROUP_JID)->sole();

    expect($group->name)->toBe('Book club')
        ->and($group->is_synced)->toBeFalse()
        ->and($group->type->slug)->toBe(WhatsappTargetType::GROUP);

    Http::assertSent(fn (Request $request): bool => $request->url() === config('services.evolution.url').'/group/inviteInfo/'.$this->account->instance_name.'?inviteCode=F1EX5QZxO181L3TMVP31gY');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/group/acceptInviteCode/'.$this->account->instance_name.'?inviteCode=F1EX5QZxO181L3TMVP31gY'));

    // Without joining, with a typed name; a revoked link is refused.
    $group->delete();
    FakeEvolutionApi::install();

    $component->call('create')
        ->set('joinGroup', false)
        ->set('identifier', 'F1EX5QZxO181L3TMVP31gY')
        ->set('name', 'Books')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->account->targets()->where('jid', FakeEvolutionApi::INVITE_GROUP_JID)->sole()->name)->toBe('Books');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/group/acceptInviteCode/'));

    FakeEvolutionApi::install(['groupInviteInfo' => Http::response(['status' => 404, 'error' => 'Not Found'], 404)]);

    $component->call('create')
        ->set('identifier', 'https://chat.whatsapp.com/Revoked0000000000000')
        ->call('save')
        ->assertHasErrors('identifier');

    // The Evolution API down: an error on the field, nothing added.
    FakeEvolutionApi::install(['groupInviteInfo' => Http::response(['status' => 500], 500)]);

    $component->set('identifier', 'https://chat.whatsapp.com/Another00000000000000')
        ->call('save')
        ->assertHasErrors('identifier');

    // A number not connected cannot read invite links.
    $this->account->update(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::CLOSED)]);
    FakeEvolutionApi::install();

    $component->set('identifier', 'https://chat.whatsapp.com/Another00000000000000')
        ->call('save')
        ->assertHasErrors('identifier');

    Http::assertNothingSent();
});

test('the contacts screen syncs a connected account', function () {
    Queue::fake([SyncWhatsappTargets::class]);

    $component = Livewire::actingAs($this->owner)
        ->test(TargetList::class, ['account' => $this->account])
        ->assertSeeHtml('data-target-resync')
        ->call('resync')
        ->assertSeeHtml('data-target-status');

    Queue::assertPushed(SyncWhatsappTargets::class, fn (SyncWhatsappTargets $job): bool => $job->account->is($this->account));

    // The list follows the sync without a refresh.
    WhatsappTarget::factory()->contact()->for($this->account, 'account')->create(['name' => 'Dora']);

    $component->call('refreshTargets')->assertSee('Dora');
});
