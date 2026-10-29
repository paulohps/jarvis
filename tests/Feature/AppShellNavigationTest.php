<?php

use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('navigation shows only enabled modules', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('data-navigation-item="shopping"', false)
        ->assertSee('Shopping list')
        ->assertDontSee('data-navigation-item="invoices"', false)
        ->assertDontSee('data-navigation-item="whatsapp"', false)
        ->assertDontSee('data-navigation-item="reminders"', false)
        ->assertDontSee('Invoices')
        ->assertDontSee('WhatsApp')
        ->assertDontSee('Reminders');
});

test('a module the user lost access to leaves the menu', function () {
    $user = User::factory()->withModules([Module::SHOPPING, Module::REMINDERS])->create();
    $user->membershipOf(WorkspaceFactory::household())->modules()->detach(Module::idFor(Module::REMINDERS));

    $this->actingAs($user->fresh())
        ->get('/')
        ->assertSee('data-navigation-item="shopping"', false)
        ->assertDontSee('data-navigation-item="reminders"', false);
});

test('the super admin sees every active module in menu order', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/')
        ->assertSeeInOrder([
            'data-navigation-item="home"',
            'data-navigation-item="invoices"',
            'data-navigation-item="messaging"',
            'data-navigation-item="shopping"',
            'data-navigation-item="reminders"',
            'data-navigation-item="admin"',
        ], false);
});

test('admin entry is visible only to super admin', function () {
    $this->actingAs(User::factory()->withModules([Module::INVOICES])->create())
        ->get('/')
        ->assertDontSee('data-navigation-item="admin"', false);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/')
        ->assertSee('data-navigation-item="admin"', false);
});

test('the active link is highlighted', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSee('aria-current="page"', false)
        ->assertSeeInOrder(['href="'.route('dashboard').'"', 'aria-current="page"', 'data-navigation-item="home"'], false);
});

test('the shell offers a skip link to the main content', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSeeInOrder(['href="#main-content"', 'Skip to content', 'id="main-content"'], false);
});

test('the shell has a desktop sidebar and a mobile tab bar', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSee('hidden w-64 flex-col', false)
        ->assertSee('lg:flex', false)
        ->assertSee('fixed inset-x-0 bottom-0', false)
        ->assertSee('lg:hidden', false)
        ->assertSee('data-slot="top-bar-actions"', false)
        ->assertSee('width=device-width, initial-scale=1', false);
});

test('the desktop sidebar collapses to its icons, remembered by the browser', function () {
    $this->actingAs(User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]))
        ->get('/')
        ->assertSee('id="app-sidebar"', false)
        ->assertSee('data-sidebar-toggle', false)
        ->assertSee('aria-controls="app-sidebar"', false)
        ->assertSee('Recolher barra lateral')
        // Applied before the first paint, so the page does not jump.
        ->assertSee("localStorage.getItem('jarvis.sidebar') === 'collapsed'", false);
});

test('the shell answers in the user language', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create([
        'locale_id' => Locale::idFor(Locale::PT_BR),
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertSee('Lista de compras')
        ->assertSee('Pular para o conteúdo');
});

test('horizon links end the sidebar for the super admin only', function () {
    $this->actingAs(User::factory()->withModules([Module::INVOICES])->create())
        ->get('/')
        ->assertDontSee('data-navigation-tools', false)
        ->assertDontSee('data-navigation-item="horizon"', false);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/')
        ->assertSeeInOrder([
            'data-navigation-item="admin"',
            'data-navigation-tools',
            'data-navigation-item="horizon"',
        ], false)
        ->assertSee('href="'.route('horizon.index', ['view' => 'dashboard']).'"', false)
        ->assertDontSee('data-navigation-item="horizon-failed"', false);
});

test('the admin screens have their own sidebar section above the tools', function () {
    $this->actingAs(User::factory()->withModules([Module::INVOICES])->create())
        ->get('/')
        ->assertDontSee('data-navigation-admin', false)
        ->assertDontSee('data-navigation-item="admin-users"', false);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/')
        ->assertSeeInOrder([
            'data-navigation-item="reminders"',
            'data-navigation-admin',
            'data-navigation-item="admin"',
            'data-navigation-item="admin-people"',
            'data-navigation-item="admin-users"',
            'data-navigation-item="admin-invitations"',
            'data-navigation-item="admin-workspaces"',
            'data-navigation-item="admin-ai"',
            'data-navigation-item="admin-ai-providers"',
            'data-navigation-item="admin-ai-usage"',
            'data-navigation-item="admin-notifications"',
            'data-navigation-item="admin-mail-services"',
            'data-navigation-tools',
            'data-navigation-item="horizon"',
        ], false);
});

test('WhatsApp and Chats are one entry, by what the user does: inbox, scheduled, history and channels', function () {
    $this->actingAs(User::factory()->withModules([Module::WHATSAPP])->create())
        ->get('/')
        ->assertSeeInOrder([
            'data-navigation-group="messaging"',
            'href="'.route('whatsapp.chat').'"',
            'data-navigation-item="chats-inbox"',
            'data-navigation-item="chats-scheduled"',
            'data-navigation-item="chats-history"',
            'href="'.route('whatsapp.accounts.index').'"',
            'data-navigation-item="chats-channels"',
        ], false)
        ->assertDontSee('data-navigation-item="whatsapp"', false);

    $this->actingAs(User::factory()->withModules([Module::WHATSAPP, Module::CHATS])->create())
        ->get('/')
        ->assertSeeInOrder([
            'href="'.route('chats.chat').'"',
            'data-navigation-item="chats-inbox"',
            'href="'.route('chats.scheduled').'"',
            'data-navigation-item="chats-scheduled"',
            'href="'.route('chats.history').'"',
            'data-navigation-item="chats-history"',
            'href="'.route('chats.index').'"',
            'data-navigation-item="chats-channels"',
        ], false)
        ->assertDontSee('href="'.route('whatsapp.chat').'"', false)
        ->assertDontSee('href="'.route('whatsapp.messages.index').'"', false);

    $this->actingAs(User::factory()->withModules([Module::CHATS])->create())
        ->get('/')
        ->assertSee('data-navigation-item="chats-inbox"', false)
        ->assertSee('data-navigation-item="chats-scheduled"', false);

    $this->actingAs(User::factory()->withModules([Module::SHOPPING])->create())
        ->get('/')
        ->assertDontSee('data-navigation-group="messaging"', false);
});

test('the screens of a group are tabs over the page, the one shown marked', function () {
    $this->actingAs(User::factory()->withModules([Module::WHATSAPP])->create())
        ->get(route('whatsapp.messages.index'))
        ->assertOk()
        ->assertSee('data-section-tabs="messaging"', false)
        ->assertSeeInOrder(['aria-current="page"', 'data-section-tab="chats-scheduled"'], false);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.invitations.index'))
        ->assertSee('data-section-tabs="admin-people"', false)
        ->assertSee('data-section-tab="admin-workspaces"', false)
        ->assertDontSee('data-section-tab="admin-ai-usage"', false);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.notifications.index'))
        ->assertDontSee('data-section-tabs=', false);
});
