<?php

use App\Livewire\Admin\Workspaces\WorkspaceDetail;
use App\Livewire\Admin\Workspaces\WorkspaceList;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\ModuleEnabled;
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

test('the super admin creates a workspace and lands on its page', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(WorkspaceList::class)
        ->set('name', '  Silva family ')
        ->set('color', 'green')
        ->call('create')
        ->assertHasNoErrors();

    $workspace = Workspace::query()->where('name', 'Silva family')->sole();

    $component->assertRedirect(route('admin.workspaces.show', $workspace));

    expect($workspace->color)->toBe('green')
        ->and($workspace->created_by_id)->toBe($this->admin->id)
        ->and($workspace->memberships()->exists())->toBeFalse();
});

test('creating a workspace needs a name and a known color', function () {
    Livewire::actingAs($this->admin)
        ->test(WorkspaceList::class)
        ->set('name', '')
        ->set('color', 'chartreuse')
        ->call('create')
        ->assertHasErrors(['name', 'color']);

    expect(Workspace::query()->where('name', '')->exists())->toBeFalse();
});

test('the list shows every workspace with its member count', function () {
    $other = Workspace::factory()->create(['name' => 'Costa family']);
    User::factory()->count(2)->inWorkspace($other)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.workspaces.index'))
        ->assertOk()
        ->assertSeeLivewire(WorkspaceList::class)
        ->assertSee("data-workspace=\"{$other->friendly_id}\"", false)
        ->assertSee('Costa family')
        ->assertSee(route('admin.workspaces.show', $other), false);
});

test('the super admin renames and recolors a workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Old', 'color' => 'red']);

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->assertSet('name', 'Old')
        ->set('name', 'New name')
        ->set('color', 'purple')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('status', __('Workspace saved.'));

    expect($workspace->fresh())
        ->name->toBe('New name')
        ->color->toBe('purple');
});

test('a workspace without data is deleted and its members get another default', function () {
    $workspace = Workspace::factory()->create(['name' => 'Aaa']);
    $user = User::factory()->inWorkspace($workspace)->withModules([])->create();

    expect($user->membershipOf($workspace)->is_default)->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->call('delete')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.workspaces.index'));

    expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and($user->fresh()->membershipOf(WorkspaceFactory::household())->is_default)->toBeTrue();
});

test('a workspace with data cannot be deleted', function () {
    $workspace = Workspace::factory()->create();
    ShoppingList::factory()->inWorkspace($workspace)->create();

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->call('delete')
        ->assertHasErrors(['workspace'])
        ->assertNoRedirect()
        ->assertSee(__('A workspace with data cannot be deleted.'));

    expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
});

test('the super admin adds an existing user with modules, announcing them', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['name' => 'Ana']);

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->assertSee($user->email)
        ->set('newMember', $user->friendly_id)
        ->set('newMemberModules', [Module::SHOPPING, Module::REMINDERS])
        ->call('addMember')
        ->assertHasNoErrors()
        ->assertSet('newMember', '')
        ->assertSee("data-member=\"{$user->friendly_id}\"", false);

    $membership = $user->fresh()->membershipOf($workspace);

    expect($membership)->not->toBeNull()
        ->and($membership->is_default)->toBeTrue()
        ->and($membership->modules->pluck('slug')->all())->toEqualCanonicalizing([Module::SHOPPING, Module::REMINDERS])
        ->and($user->fresh()->hasModuleIn($workspace, Module::SHOPPING))->toBeTrue();

    Notification::assertSentToTimes($user, ModuleEnabled::class, 2);
});

test('only users who are not members can be added', function () {
    $member = User::factory()->withModules([])->create();

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => WorkspaceFactory::household()])
        ->set('newMember', $member->friendly_id)
        ->call('addMember')
        ->assertHasErrors(['newMember']);

    expect(WorkspaceMembership::query()->where('user_id', $member->id)->count())->toBe(1);
});

test('the super admin removes a member, who stops seeing the workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->withModules([Module::SHOPPING])->inWorkspace($workspace, [Module::SHOPPING])->create();
    $membership = $user->membershipOf($workspace);

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->call('removeMember', $membership->id)
        ->assertHasNoErrors()
        ->assertDontSee("data-member=\"{$user->friendly_id}\"", false);

    expect($user->fresh()->workspaceIdsWithModule(Module::SHOPPING))->toBe([WorkspaceFactory::household()->id]);
});

test('a member of another workspace cannot be changed from this page', function () {
    $workspace = Workspace::factory()->create();
    $outsider = User::factory()->withModules([])->create();

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->call('removeMember', $outsider->membershipOf(WorkspaceFactory::household())->id)
        ->assertNotFound();

    expect($outsider->fresh()->memberships)->toHaveCount(1);
});

test('the super admin sets a member color and makes the workspace their default', function () {
    $workspace = Workspace::factory()->create(['color' => 'blue']);
    $user = User::factory()->withModules([])->inWorkspace($workspace)->create();
    $membership = $user->membershipOf($workspace);

    expect($membership->is_default)->toBeFalse();

    $component = Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $workspace])
        ->set("memberColors.{$membership->id}", 'pink')
        ->assertHasNoErrors();

    expect($user->fresh()->colorOf($workspace))->toBe('pink');

    $component->call('makeDefault', $membership->id)->assertHasNoErrors();

    expect($membership->fresh()->is_default)->toBeTrue()
        ->and($user->fresh()->membershipOf(WorkspaceFactory::household())->is_default)->toBeFalse();

    $component->set("memberColors.{$membership->id}", '');

    expect($user->fresh()->colorOf($workspace))->toBe('blue');
});

test('only the super admin opens the workspace admin', function () {
    $member = User::factory()->withModules([Module::SHOPPING])->create();
    $workspace = WorkspaceFactory::household();

    $this->actingAs($member)->get(route('admin.workspaces.index'))->assertForbidden();
    $this->actingAs($member)->get(route('admin.workspaces.show', $workspace))->assertForbidden();

    Livewire::actingAs($member)->test(WorkspaceList::class)->assertForbidden();
    Livewire::actingAs($member)->test(WorkspaceDetail::class, ['workspace' => $workspace])->assertForbidden();
});
