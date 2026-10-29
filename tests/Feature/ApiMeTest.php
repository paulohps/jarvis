<?php

use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('me returns enabled module slugs', function () {
    $user = User::factory()->withModules([Module::REMINDERS, Module::INVOICES])->create([
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'timezone' => 'America/Sao_Paulo',
    ]);

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson(['data' => [
            'friendly_id' => $user->friendly_id,
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'avatar_url' => null,
            'locale' => 'en',
            'timezone' => 'America/Sao_Paulo',
            'is_super_admin' => false,
            'modules' => [
                ['slug' => Module::INVOICES, 'name' => 'Invoices', 'is_available_on_mobile' => false],
                ['slug' => Module::REMINDERS, 'name' => 'Agenda', 'is_available_on_mobile' => true],
            ],
            'workspaces' => [[
                'id' => WorkspaceFactory::household()->friendly_id,
                'name' => WorkspaceFactory::HOUSEHOLD,
                'color' => 'blue',
                'color_hex' => '#007aff',
                'workspace_color' => 'blue',
                'own_color' => null,
                'is_default' => true,
                'modules' => [Module::INVOICES, Module::REMINDERS],
            ]],
            'broadcast_channel' => 'private-App.Models.User.'.$user->id,
        ]]);
});

test('super admin gets every active module', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->withToken($admin->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', true)
        ->assertJsonPath('data.modules.*.slug', [Module::INVOICES, Module::WHATSAPP, Module::SHOPPING, Module::REMINDERS, Module::CHATS]);
});

test('a user without modules gets an empty list', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.modules', []);
});

test('me requires a token', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->patchJson('/api/v1/me/preferences', ['locale' => 'pt_BR'])->assertUnauthorized();
});

test('preferences endpoint reuses update preferences action', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/v1/me/preferences', ['locale' => 'pt_BR', 'timezone' => 'America/Sao_Paulo'])
        ->assertOk()
        ->assertJsonPath('data.locale', 'pt_BR')
        ->assertJsonPath('data.timezone', 'America/Sao_Paulo');

    expect($user->fresh()->locale_id)->toBe(Locale::idFor('pt_BR'))
        ->and($user->fresh()->timezone)->toBe('America/Sao_Paulo');

    // Either field alone, as UpdatePreferences allows.
    $this->withToken($token)
        ->patchJson('/api/v1/me/preferences', ['timezone' => 'Europe/Lisbon'])
        ->assertOk()
        ->assertJsonPath('data.locale', 'pt_BR')
        ->assertJsonPath('data.timezone', 'Europe/Lisbon');
});

test('invalid preferences return 422', function (array $input, string $field) {
    $user = User::factory()->create(['timezone' => 'UTC']);

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->patchJson('/api/v1/me/preferences', $input)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($user->fresh()->timezone)->toBe('UTC')
        ->and($user->fresh()->locale->slug)->toBe('en');
})->with([
    'unsupported locale' => [['locale' => 'fr'], 'locale'],
    'invalid timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'empty locale' => [['locale' => ''], 'locale'],
]);

test('me lists every workspace with the modules on there and the color the user sees it in', function () {
    $family = Workspace::factory()->create(['name' => 'Alves family', 'color' => 'green']);
    $user = User::factory()
        ->withModules([Module::SHOPPING])
        ->inWorkspace($family, [Module::REMINDERS], color: 'purple')
        ->create();

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.modules.*.slug', [Module::SHOPPING, Module::REMINDERS])
        ->assertJsonPath('data.workspaces.*.name', ['Alves family', WorkspaceFactory::HOUSEHOLD])
        ->assertJsonPath('data.workspaces.0.color', 'purple')
        ->assertJsonPath('data.workspaces.0.workspace_color', 'green')
        ->assertJsonPath('data.workspaces.0.is_default', false)
        ->assertJsonPath('data.workspaces.0.modules', [Module::REMINDERS])
        ->assertJsonPath('data.workspaces.1.is_default', true)
        ->assertJsonPath('data.workspaces.1.modules', [Module::SHOPPING]);
});

test('a user changes their own color of a workspace and their default', function () {
    $family = Workspace::factory()->create(['name' => 'Alves family', 'color' => 'green']);
    $user = User::factory()->withModules([Module::SHOPPING])->inWorkspace($family, [Module::SHOPPING])->create();
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->patchJson("/api/v1/me/workspaces/{$family->friendly_id}", ['color' => 'pink', 'is_default' => true])
        ->assertOk()
        ->assertJsonPath('data.color', 'pink')
        ->assertJsonPath('data.color_hex', '#ff2d55')
        ->assertJsonPath('data.is_default', true);

    expect($user->fresh()->defaultWorkspaceFor(Module::SHOPPING)?->is($family))->toBeTrue();

    $this->withToken($token)
        ->patchJson("/api/v1/me/workspaces/{$family->friendly_id}", ['color' => null])
        ->assertOk()
        ->assertJsonPath('data.color', 'green')
        ->assertJsonPath('data.own_color', null);

    $this->withToken($token)
        ->patchJson("/api/v1/me/workspaces/{$family->friendly_id}", ['color' => 'neon'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('color');

    $this->withToken($token)
        ->patchJson("/api/v1/me/workspaces/{$family->friendly_id}", ['is_default' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_default');
});

test('a user cannot change a workspace they are not in', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create();
    $other = Workspace::factory()->create();

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->patchJson("/api/v1/me/workspaces/{$other->friendly_id}", ['color' => 'pink'])
        ->assertNotFound();
});

test('the workspace colors are listed by name and hex', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/workspace-colors')
        ->assertOk()
        ->assertJsonCount(count(Workspace::COLORS), 'data')
        ->assertJsonPath('data.0', ['name' => 'red', 'hex' => '#ff3b30']);
});
