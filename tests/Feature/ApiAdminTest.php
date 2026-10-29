<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\InvitationReceived;
use App\Services\Notifications\Types\ModuleEnabled;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    app(NotificationProvisioner::class)->provisionAll();

    Notification::fake();

    $this->admin = User::factory()->superAdmin()->create(['name' => 'Admin']);
    $this->token = $this->admin->createToken('iPhone')->plainTextToken;
});

test('only the super admin reaches the admin api', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create();
    $workspace = WorkspaceFactory::household();
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/workspaces')->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/admin/workspaces', ['name' => 'X', 'color' => 'red'])->assertForbidden();
    $this->withToken($token)->getJson("/api/v1/admin/workspaces/{$workspace->friendly_id}")->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/admin/users')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/admin/invitations')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/admin/modules')->assertForbidden();
});

test('the admin api requires a token', function () {
    $this->getJson('/api/v1/admin/workspaces')->assertUnauthorized();
});

test('the super admin creates, lists, renames and recolors workspaces', function () {
    $created = $this->withToken($this->token)
        ->postJson('/api/v1/admin/workspaces', ['name' => '  Alves family ', 'color' => 'green'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Alves family')
        ->assertJsonPath('data.color_hex', '#34c759')
        ->assertJsonPath('data.members_count', 0)
        ->assertJsonPath('data.has_data', false)
        ->assertJsonPath('data.members', [])
        ->json('data.id');

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/workspaces')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Alves family', WorkspaceFactory::HOUSEHOLD])
        ->assertJsonPath('data.1.members_count', 1);

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/workspaces/{$created}", ['name' => 'Alves', 'color' => 'teal'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Alves')
        ->assertJsonPath('data.color', 'teal');

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/workspaces', ['name' => '', 'color' => 'neon'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'color']);
});

test('a workspace with data cannot be deleted, an empty one can', function () {
    $empty = Workspace::factory()->create();
    $member = User::factory()->inWorkspace($empty, [Module::SHOPPING])->withModules([Module::SHOPPING])->create();
    $household = WorkspaceFactory::household();
    ShoppingList::factory()->create(['workspace_id' => $household->id]);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/workspaces/{$household->friendly_id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workspace');

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/workspaces/{$empty->friendly_id}")
        ->assertNoContent();

    expect(Workspace::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and($member->fresh()->memberships)->toHaveCount(1)
        ->and($member->fresh()->memberships->first()->is_default)->toBeTrue();
});

test('the super admin adds a member with modules, changes them and removes the member', function () {
    $workspace = Workspace::factory()->create(['name' => 'Alves family', 'color' => 'green']);
    $user = User::factory()->withModules([Module::SHOPPING])->create(['name' => 'Bia']);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/workspaces/{$workspace->friendly_id}/members", [
            'user' => $user->friendly_id,
            'modules' => [Module::SHOPPING, Module::REMINDERS],
            'color' => 'purple',
        ])
        ->assertCreated()
        ->assertJsonPath('data.user.name', 'Bia')
        ->assertJsonPath('data.color', 'purple')
        ->assertJsonPath('data.effective_color_hex', '#af52de')
        ->assertJsonPath('data.is_default', false)
        ->assertJsonPath('data.modules', [Module::SHOPPING, Module::REMINDERS]);

    // Only the module Bia had nowhere is announced.
    Notification::assertSentToTimes($user, ModuleEnabled::class, 1);
    Notification::assertSentTo($user, ModuleEnabled::class, fn (ModuleEnabled $notification): bool => $notification->enabledModule->slug === Module::REMINDERS);

    expect($user->fresh()->workspaceIdsWithModule(Module::SHOPPING))->toEqualCanonicalizing([WorkspaceFactory::household()->id, $workspace->id]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/workspaces/{$workspace->friendly_id}/members", ['user' => $user->friendly_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user');

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/workspaces/{$workspace->friendly_id}/members/{$user->friendly_id}", [
            'modules' => [Module::REMINDERS],
            'color' => null,
            'is_default' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.modules', [Module::REMINDERS])
        ->assertJsonPath('data.color', null)
        ->assertJsonPath('data.effective_color', 'green')
        ->assertJsonPath('data.is_default', true);

    expect($user->fresh()->workspaceIdsWithModule(Module::SHOPPING))->toBe([WorkspaceFactory::household()->id])
        ->and($user->fresh()->defaultWorkspaceFor(Module::REMINDERS)?->is($workspace))->toBeTrue();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/workspaces/{$workspace->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.members_count', 1)
        ->assertJsonPath('data.members.0.user.id', $user->friendly_id);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/workspaces/{$workspace->friendly_id}/members/{$user->friendly_id}")
        ->assertNoContent();

    // The default goes back to the workspace left.
    $memberships = $user->fresh()->memberships;
    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->workspace_id)->toBe(WorkspaceFactory::household()->id)
        ->and($memberships->first()->is_default)->toBeTrue();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/workspaces/{$workspace->friendly_id}/members/{$user->friendly_id}")
        ->assertNotFound();
});

test('the super admin has every module in their workspaces, so their modules cannot be set', function () {
    $household = WorkspaceFactory::household();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/workspaces/{$household->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.members.0.modules', [Module::INVOICES, Module::WHATSAPP, Module::SHOPPING, Module::REMINDERS, Module::CHATS]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/workspaces/{$household->friendly_id}/members/{$this->admin->friendly_id}", ['modules' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user');
});

test('the super admin sees nothing of a workspace they are not a member of', function () {
    $other = Workspace::factory()->create();
    ShoppingList::factory()->create(['workspace_id' => $other->id]);

    expect($this->admin->hasModule(Module::SHOPPING))->toBeTrue()
        ->and($this->admin->workspaceIdsWithModule(Module::SHOPPING))->toBe([WorkspaceFactory::household()->id])
        ->and(ShoppingList::query()->whereIn('workspace_id', $this->admin->workspaceIdsWithModule(Module::SHOPPING))->count())->toBe(0);
});

test('the super admin lists users with their workspaces and deactivates and reactivates them', function () {
    $family = Workspace::factory()->create(['name' => 'Alves family', 'color' => 'green']);
    $user = User::factory()->inWorkspace($family, [Module::SHOPPING], color: 'pink')->create(['name' => 'Bia']);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Admin', 'Bia'])
        ->assertJsonPath('data.1.workspaces', [[
            'id' => $family->friendly_id,
            'name' => 'Alves family',
            'color' => 'pink',
            'color_hex' => '#ff2d55',
        ]]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/users/{$user->friendly_id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_deactivated', true);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/users/{$user->friendly_id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.is_deactivated', false);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/users/{$this->admin->friendly_id}/deactivate")
        ->assertUnprocessable();
});

test('the super admin invites to a workspace, resends and revokes', function () {
    $family = Workspace::factory()->create(['name' => 'Alves family']);

    $invitationId = $this->withToken($this->token)
        ->postJson('/api/v1/admin/invitations', [
            'email' => 'Bia@Example.com',
            'workspace' => $family->friendly_id,
            'modules' => [Module::SHOPPING],
            'locale' => 'pt_BR',
        ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'bia@example.com')
        ->assertJsonPath('data.status', Invitation::STATUS_PENDING)
        ->assertJsonPath('data.workspace.id', $family->friendly_id)
        ->assertJsonPath('data.modules', [Module::SHOPPING])
        ->assertJsonPath('data.locale', 'pt_BR')
        ->json('data.id');

    Notification::assertSentOnDemandTimes(InvitationReceived::class, 1);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/invitations', ['email' => 'carla@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workspace');

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/invitations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $invitationId);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/invitations/{$invitationId}/resend")
        ->assertOk()
        ->assertJsonPath('data.status', Invitation::STATUS_PENDING);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/invitations/{$invitationId}/revoke")
        ->assertOk()
        ->assertJsonPath('data.status', Invitation::STATUS_REVOKED);
});

test('the super admin lists the modules in menu order', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/admin/modules')
        ->assertOk()
        ->assertJsonPath('data.*.slug', [Module::INVOICES, Module::WHATSAPP, Module::SHOPPING, Module::REMINDERS, Module::CHATS])
        ->assertJsonPath('data.0.name', 'Invoices');
});

test('accepting an invitation makes the new user a member of its workspace with its modules', function () {
    $family = Workspace::factory()->create();
    $invitation = Invitation::factory()->create(['workspace_id' => $family->id]);
    $invitation->modules()->sync([Module::idFor(Module::SHOPPING)]);

    $user = app(AcceptInvitation::class)->createAccount($invitation, 'Bia', 'secret-password-1');

    $membership = WorkspaceMembership::query()->where('user_id', $user->id)->sole();

    expect($membership->workspace_id)->toBe($family->id)
        ->and($membership->is_default)->toBeTrue()
        ->and($user->workspaceIdsWithModule(Module::SHOPPING))->toBe([$family->id])
        ->and($user->hasModule(Module::REMINDERS))->toBeFalse();
});
