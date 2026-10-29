<?php

use App\Console\Commands\InstallJarvis;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('install asks for the super admin and creates them with their workspace', function () {
    $this->artisan('jarvis:install')
        ->expectsQuestion('Name', 'Jane Doe')
        ->expectsQuestion('E-mail', ' Jane@Example.com ')
        ->expectsQuestion('Password', 'a-good-password')
        ->assertSuccessful();

    $superAdmin = User::query()->sole();
    $membership = WorkspaceMembership::query()->sole();

    expect($superAdmin->name)->toBe('Jane Doe')
        ->and($superAdmin->email)->toBe('jane@example.com')
        ->and($superAdmin->is_super_admin)->toBeTrue()
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-good-password', $superAdmin->password))->toBeTrue()
        ->and($superAdmin->friendly_id)->toStartWith('usr_')
        ->and($superAdmin->locale_id)->toBe(DB::table('locales')->where('slug', 'en')->value('id'))
        ->and(Workspace::query()->sole()->name)->toBe(InstallJarvis::WORKSPACE_NAME)
        ->and($membership->user_id)->toBe($superAdmin->id)
        ->and($membership->is_default)->toBeTrue()
        ->and(DB::table('modules')->count())->toBeGreaterThan(0)
        ->and(NotificationType::query()->count())->toBeGreaterThan(0);
});

test('install takes the super admin from its options without asking', function () {
    $this->artisan('jarvis:install', [
        '--name' => 'Jane Doe',
        '--email' => 'jane@example.com',
        '--password' => 'a-good-password',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->sole()->is_super_admin)->toBeTrue();
});

test('install without interaction fails when an option is missing', function () {
    $this->artisan('jarvis:install', ['--name' => 'Jane Doe', '--no-interaction' => true])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('install refuses an e-mail that already has an account', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->artisan('jarvis:install', [
        '--name' => 'Jane Doe',
        '--email' => 'JANE@example.com',
        '--password' => 'a-good-password',
    ])->assertFailed();

    expect(User::query()->where('is_super_admin', true)->exists())->toBeFalse()
        ->and(Workspace::query()->count())->toBe(0);
});

test('install run again keeps the existing super admin', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->artisan('jarvis:install')
        ->expectsOutputToContain('The super admin already exists')
        ->assertSuccessful();

    expect(User::query()->sole()->is($superAdmin))->toBeTrue()
        ->and(Workspace::query()->count())->toBe(0);
});
