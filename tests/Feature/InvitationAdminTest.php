<?php

use App\Livewire\Admin\Invitations\InvitationList;
use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\InvitationReceived;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    app(NotificationProvisioner::class)->provisionAll();

    Notification::fake();

    $this->admin = User::factory()->superAdmin()->create();
});

test('non super admin cannot open the invitations screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.invitations.index'))
        ->assertForbidden();
});

test('super admin invites from the form into their default workspace', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.invitations.index'))
        ->assertOk()
        ->assertSeeLivewire(InvitationList::class)
        ->assertSee('data-invite-module="shopping"', false)
        ->assertSee('data-invite-workspace', false);

    $household = WorkspaceFactory::household();

    Livewire::actingAs($this->admin)
        ->test(InvitationList::class)
        ->assertSet('workspace', $household->friendly_id)
        ->set('email', 'ana@example.com')
        ->set('locale', Locale::PT_BR)
        ->set('modules', [Module::INVOICES])
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSet('email', '')
        ->assertSet('modules', [])
        ->assertSet('workspace', $household->friendly_id)
        ->assertSee('Invitation sent to ana@example.com.')
        ->assertSee('data-invitation-status="pending"', false)
        ->assertSee('data-invitation-workspace="'.$household->friendly_id.'"', false);

    $invitation = Invitation::query()->sole();

    expect($invitation->email)->toBe('ana@example.com')
        ->and($invitation->workspace_id)->toBe($household->id)
        ->and($invitation->locale->slug)->toBe(Locale::PT_BR)
        ->and($invitation->modules()->pluck('slug')->all())->toBe([Module::INVOICES]);

    Notification::assertSentOnDemandTimes(InvitationReceived::class, 1);
});

test('super admin invites into another workspace', function () {
    $costa = Workspace::factory()->create(['name' => 'Costa family']);

    Livewire::actingAs($this->admin)
        ->test(InvitationList::class)
        ->assertSee('Costa family')
        ->set('email', 'bia@example.com')
        ->set('workspace', $costa->friendly_id)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSee('data-invitation-workspace="'.$costa->friendly_id.'"', false);

    expect(Invitation::query()->sole()->workspace_id)->toBe($costa->id);
});

test('the workspace is required', function () {
    Livewire::actingAs($this->admin)
        ->test(InvitationList::class)
        ->set('email', 'bia@example.com')
        ->set('workspace', '')
        ->call('invite')
        ->assertHasErrors(['workspace' => 'required']);

    expect(Invitation::query()->count())->toBe(0);
});

test('the form shows the validation errors', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    Livewire::actingAs($this->admin)
        ->test(InvitationList::class)
        ->set('email', 'ana@example.com')
        ->call('invite')
        ->assertHasErrors(['email' => 'User already exists.']);

    expect(Invitation::query()->count())->toBe(0);
});

test('the list shows each derived status and actions only for pending invitations', function () {
    $pending = Invitation::factory()->create(['email' => 'pending@example.com']);
    $accepted = Invitation::factory()->accepted()->create(['email' => 'accepted@example.com']);
    $expired = Invitation::factory()->expired()->create(['email' => 'expired@example.com']);
    $revoked = Invitation::factory()->revoked()->create(['email' => 'revoked@example.com']);

    $html = Livewire::actingAs($this->admin)->test(InvitationList::class)->html();

    foreach (['pending' => $pending, 'accepted' => $accepted, 'expired' => $expired, 'revoked' => $revoked] as $status => $invitation) {
        expect($html)->toMatch("/data-invitation=\"{$invitation->friendly_id}\"\\s+data-invitation-status=\"{$status}\"/");
    }

    expect(substr_count($html, 'data-invitation-actions'))->toBe(1)
        ->and($html)->toContain("wire:click=\"resend({$pending->id})\"")
        ->and($html)->toContain("wire:click=\"revoke({$pending->id})\"")
        ->and($html)->not->toContain("resend({$accepted->id})")
        ->and($html)->not->toContain("resend({$expired->id})")
        ->and($html)->not->toContain("resend({$revoked->id})");
});

test('super admin resends and revokes from the list', function () {
    $invitation = Invitation::factory()->create(['email' => 'ana@example.com']);
    $oldHash = $invitation->token_hash;

    $component = Livewire::actingAs($this->admin)
        ->test(InvitationList::class)
        ->call('resend', $invitation->id)
        ->assertHasNoErrors()
        ->assertSee('Invitation sent to ana@example.com again.');

    expect($invitation->fresh()->token_hash)->not->toBe($oldHash);

    $component->call('revoke', $invitation->id)
        ->assertHasNoErrors()
        ->assertSee('data-invitation-status="revoked"', false);

    expect($invitation->fresh()->status)->toBe(Invitation::STATUS_REVOKED);

    $component->call('resend', $invitation->id)->assertHasErrors('invitation');
});
