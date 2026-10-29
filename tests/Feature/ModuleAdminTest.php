<?php

use App\Actions\Workspaces\SetMemberModules;
use App\Livewire\Admin\Workspaces\WorkspaceDetail;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\ModuleEnabled;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    app(NotificationProvisioner::class)->provisionAll();

    Notification::fake();

    $this->admin = User::factory()->superAdmin()->create();
    $this->household = WorkspaceFactory::household();

    Route::middleware(['web', 'auth', 'module:'.Module::SHOPPING])->group(function () {
        Route::get('/module-probe/shopping', fn () => ShoppingList::query()
            ->whereIn('workspace_id', auth()->user()->workspaceIdsWithModule(Module::SHOPPING))
            ->pluck('name')
            ->join(','));
    });
});

test('switching a module on for a member gives access and notifies the user', function () {
    $user = User::factory()->withModules([])->create();
    $membership = $user->membershipOf($this->household);

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $this->household])
        ->assertSet("access.{$membership->id}.".Module::SHOPPING, false)
        ->set("access.{$membership->id}.".Module::SHOPPING, true)
        ->assertHasNoErrors()
        ->assertSet("access.{$membership->id}.".Module::SHOPPING, true);

    $fresh = $user->fresh();

    expect($fresh->hasModule(Module::SHOPPING))->toBeTrue()
        ->and($fresh->hasModuleIn($this->household, Module::SHOPPING))->toBeTrue()
        ->and($membership->modules()->sole()->pivot->enabled_by_id)->toBe($this->admin->id);

    Notification::assertSentToTimes($user, ModuleEnabled::class, 1);
    Notification::assertSentTo($user, ModuleEnabled::class, fn (ModuleEnabled $notification): bool => $notification->enabledModule->slug === Module::SHOPPING);
});

test('a module the user already has in another workspace is switched on quietly', function () {
    $other = Workspace::factory()->create();
    $user = User::factory()->withModules([Module::SHOPPING])->inWorkspace($other)->create();
    $membership = $user->membershipOf($other);

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $other])
        ->set("access.{$membership->id}.".Module::SHOPPING, true)
        ->set("access.{$membership->id}.".Module::REMINDERS, true)
        ->assertHasNoErrors();

    expect($user->fresh()->workspaceIdsWithModule(Module::SHOPPING))->toEqualCanonicalizing([$this->household->id, $other->id]);

    Notification::assertSentToTimes($user, ModuleEnabled::class, 1);
    Notification::assertSentTo($user, ModuleEnabled::class, fn (ModuleEnabled $notification): bool => $notification->enabledModule->slug === Module::REMINDERS);
});

test('switching a module off keeps the data and gives it back when switched on again', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create();
    $membership = $user->membershipOf($this->household);
    ShoppingList::factory()->for($user, 'createdBy')->inWorkspace($this->household)->create(['name' => 'Groceries']);

    $this->actingAs($user)->get('/module-probe/shopping')->assertOk()->assertSee('Groceries');

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $this->household])
        ->set("access.{$membership->id}.".Module::SHOPPING, false)
        ->assertHasNoErrors()
        ->assertSet("access.{$membership->id}.".Module::SHOPPING, false);

    expect(ShoppingList::query()->where('name', 'Groceries')->exists())->toBeTrue();
    Notification::assertNotSentTo($user, ModuleEnabled::class);

    $this->actingAs($user->fresh())->get('/module-probe/shopping')->assertForbidden();

    app(SetMemberModules::class)($this->admin, $membership->fresh(), [Module::SHOPPING]);

    $this->actingAs($user->fresh())->get('/module-probe/shopping')->assertOk()->assertSee('Groceries');
});

test('only the super admin sets modules, and never their own', function () {
    $member = User::factory()->withModules([Module::SHOPPING])->create();
    $target = User::factory()->withModules([Module::REMINDERS])->create();

    $this->actingAs($member)->get(route('admin.workspaces.show', $this->household))->assertForbidden();

    Livewire::actingAs($member)->test(WorkspaceDetail::class, ['workspace' => $this->household])->assertForbidden();

    expect(fn () => app(SetMemberModules::class)($member, $target->membershipOf($this->household), [Module::SHOPPING]))->toThrow(AuthorizationException::class)
        ->and($target->fresh()->hasModule(Module::SHOPPING))->toBeFalse()
        ->and($target->fresh()->hasModule(Module::REMINDERS))->toBeTrue();

    expect(fn () => app(SetMemberModules::class)($this->admin, $this->admin->membershipOf($this->household), []))->toThrow(ValidationException::class);

    $this->actingAs($this->admin)
        ->get(route('admin.workspaces.show', $this->household))
        ->assertOk()
        ->assertSeeLivewire(WorkspaceDetail::class)
        ->assertSee("data-member=\"{$target->friendly_id}\"", false)
        ->assertSee("data-member-module=\"{$target->friendly_id}.shopping\"", false)
        ->assertDontSee("data-member-module=\"{$this->admin->friendly_id}.shopping\"", false);
});

test('the old module matrix address leads to the workspaces', function () {
    $this->actingAs($this->admin)
        ->get('/admin/modules')
        ->assertRedirect('/admin/workspaces');
});
