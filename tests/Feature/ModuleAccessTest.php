<?php

use App\Models\Module;
use App\Models\User;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Route::middleware(['web', 'auth', 'module:'.Module::SHOPPING])->group(function () {
        Route::get('/module-probe/shopping', fn () => 'shopping screen');
    });

    Route::middleware(['api', 'auth:sanctum', 'module:'.Module::SHOPPING])->prefix('api/v1')->group(function () {
        Route::get('module-probe/shopping', fn () => ['ok' => true]);
    });
});

test('web module route is forbidden without the module', function () {
    $user = User::factory()->withModules([Module::REMINDERS])->create();

    $this->actingAs($user)
        ->get('/module-probe/shopping')
        ->assertForbidden()
        ->assertSee("You don't have access to this module");

    $this->actingAs(User::factory()->withModules([Module::SHOPPING])->create())
        ->get('/module-probe/shopping')
        ->assertOk()
        ->assertSee('shopping screen');
});

test('api module route is forbidden without the module', function () {
    Sanctum::actingAs(User::factory()->withModules([Module::REMINDERS])->create());

    $this->getJson('/api/v1/module-probe/shopping')
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);

    Sanctum::actingAs(User::factory()->withModules([Module::SHOPPING])->create());

    $this->getJson('/api/v1/module-probe/shopping')->assertOk()->assertJson(['ok' => true]);
});

test('a guest is sent to sign in, not refused by the module', function () {
    $this->get('/module-probe/shopping')->assertRedirect(route('login'));
    $this->getJson('/api/v1/module-probe/shopping')->assertUnauthorized();
});

test('super admin passes every module middleware', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get('/module-probe/shopping')->assertOk();

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/module-probe/shopping')->assertOk();

    foreach ([Module::INVOICES, Module::WHATSAPP, Module::SHOPPING, Module::REMINDERS] as $slug) {
        expect(Gate::forUser($admin)->allows('access-module', $slug))->toBeTrue();
    }
});

test('revoking a module takes effect on the next request', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create();

    $this->actingAs($user)->get('/module-probe/shopping')->assertOk();

    $user->membershipOf(WorkspaceFactory::household())->modules()->detach(Module::idFor(Module::SHOPPING));

    $this->actingAs($user->fresh())->get('/module-probe/shopping')->assertForbidden();

    $user->membershipOf(WorkspaceFactory::household())->modules()->attach(Module::idFor(Module::SHOPPING));

    $this->actingAs($user->fresh())->get('/module-probe/shopping')->assertOk();
});
