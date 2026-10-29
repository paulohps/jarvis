<?php

use App\Models\Module;
use App\Support\FriendlyId;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const WORKSPACES_MIGRATION = 'database/migrations/2026_10_07_205932_create_workspaces_tables.php';

// The chat tables hang off `workspace_user`, so they go and come back with it.
const CHAT_TABLES_MIGRATION = 'database/migrations/2026_10_09_180000_create_chat_tables.php';

// And what was added onto the chat tables since, which hangs off them.
const CHAT_SCHEDULING_MIGRATIONS = [
    'database/migrations/2026_10_14_194713_add_recurrence_and_ai_to_chat_scheduled_messages_table.php',
    'database/migrations/2026_10_14_194714_create_chat_scheduled_deliveries_table.php',
];

/**
 * Inserts a legacy user (before workspaces) and returns its id.
 */
function legacyUser(string $email, bool $isSuperAdmin = false): int
{
    return DB::table('users')->insertGetId([
        'friendly_id' => FriendlyId::generate('usr'),
        'name' => $email,
        'email' => $email,
        'locale_id' => DB::table('locales')->value('id'),
        'timezone' => 'UTC',
        'is_super_admin' => $isSuperAdmin,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the existing users, their modules and the shared data move into a first workspace', function () {
    $this->seed(LookupSeeder::class);

    Artisan::call('migrate:rollback', ['--path' => [WORKSPACES_MIGRATION, CHAT_TABLES_MIGRATION, ...CHAT_SCHEDULING_MIGRATIONS], '--force' => true]);

    expect(Schema::hasTable('workspaces'))->toBeFalse()
        ->and(Schema::hasTable('module_user'))->toBeTrue();

    $adminId = legacyUser('admin@example.com', isSuperAdmin: true);
    $userId = legacyUser('bia@example.com');

    DB::table('module_user')->insert([
        'module_id' => Module::idFor(Module::SHOPPING),
        'user_id' => $userId,
        'enabled_by_id' => $adminId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('shopping_lists')->insert([
        'friendly_id' => FriendlyId::generate('shl'),
        'name' => 'Groceries',
        'is_default' => true,
        'created_by_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('migrate', ['--path' => [WORKSPACES_MIGRATION, CHAT_TABLES_MIGRATION, ...CHAT_SCHEDULING_MIGRATIONS], '--force' => true]);

    $workspace = DB::table('workspaces')->sole();

    expect($workspace->name)->toBe('Home')
        ->and($workspace->created_by_id)->toBe($adminId)
        ->and(DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('is_default', true)->pluck('user_id')->sort()->values()->all())
        ->toBe([$adminId, $userId])
        ->and(DB::table('shopping_lists')->value('workspace_id'))->toBe($workspace->id)
        ->and(Schema::hasTable('module_user'))->toBeFalse();

    $biaMembership = DB::table('workspace_user')->where('user_id', $userId)->value('id');

    expect(DB::table('module_workspace_user')->where('workspace_user_id', $biaMembership)->pluck('module_id')->all())
        ->toBe([Module::idFor(Module::SHOPPING)]);
});

test('the migration rolls back to per-user modules', function () {
    $this->seed(LookupSeeder::class);

    Artisan::call('migrate:rollback', ['--path' => [WORKSPACES_MIGRATION, CHAT_TABLES_MIGRATION, ...CHAT_SCHEDULING_MIGRATIONS], '--force' => true]);
    $userId = legacyUser('bia@example.com');
    DB::table('module_user')->insert(['module_id' => Module::idFor(Module::REMINDERS), 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

    Artisan::call('migrate', ['--path' => [WORKSPACES_MIGRATION, CHAT_TABLES_MIGRATION, ...CHAT_SCHEDULING_MIGRATIONS], '--force' => true]);
    Artisan::call('migrate:rollback', ['--path' => [WORKSPACES_MIGRATION, CHAT_TABLES_MIGRATION, ...CHAT_SCHEDULING_MIGRATIONS], '--force' => true]);

    expect(DB::table('module_user')->where('user_id', $userId)->pluck('module_id')->all())->toBe([Module::idFor(Module::REMINDERS)]);

    Artisan::call('migrate', ['--path' => [WORKSPACES_MIGRATION, CHAT_TABLES_MIGRATION, ...CHAT_SCHEDULING_MIGRATIONS], '--force' => true]);
});
