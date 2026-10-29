<?php

use App\Models\Module;
use App\Models\User;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('super admin has every module', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    expect(DB::table('module_workspace_user')->count())->toBe(0)
        ->and($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->hasModule('invoices'))->toBeTrue()
        ->and($superAdmin->hasModule(Module::REMINDERS))->toBeTrue();
});

test('regular user has only enabled modules', function () {
    $admin = User::factory()->superAdmin()->create();
    $user = User::factory()->withModules([])->create();

    $user->membershipOf(WorkspaceFactory::household())->modules()->attach(Module::idFor(Module::SHOPPING), ['enabled_by_id' => $admin->id]);
    $user->forgetMemberships();

    expect($user->isSuperAdmin())->toBeFalse()
        ->and($user->hasModule(Module::SHOPPING))->toBeTrue()
        ->and($user->hasModule(Module::INVOICES))->toBeFalse()
        ->and($user->hasModule(Module::WHATSAPP))->toBeFalse()
        ->and($user->membershipOf(WorkspaceFactory::household())->modules->first()->pivot->enabled_by_id)->toBe($admin->id)
        ->and($user->hasModule(Module::SHOPPING))->toBeTrue()
        ->and($user->hasModule(Module::REMINDERS))->toBeFalse();
});

test('password is hashed and nullable', function () {
    $user = User::factory()->create(['password' => 'secret-password']);
    $googleOnly = User::factory()->create(['password' => null]);

    expect($user->password)->not->toBe('secret-password')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and($googleOnly->fresh()->password)->toBeNull();
});

test('avatar path becomes a url', function () {
    Storage::fake('public');

    $user = User::factory()->create(['avatar_path' => 'avatars/ada.webp']);

    expect($user->avatar_url)->toBe(Storage::disk('public')->url('avatars/ada.webp'))
        ->and(User::factory()->create()->avatar_url)->toBeNull();
});

test('deactivation and soft delete come from timestamps', function () {
    $user = User::factory()->create();

    expect($user->isDeactivated())->toBeFalse();

    $user->forceFill(['deactivated_at' => now()])->save();
    $user->delete();

    expect($user->isDeactivated())->toBeTrue()
        ->and(User::query()->find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull()
        ->and($user->locale->slug)->toBe('en');
});
