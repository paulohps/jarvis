<?php

use App\Livewire\Dashboard;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\Invoice;
use App\Models\Locale;
use App\Models\Module;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00', 'UTC'));
});

test('guests are redirected to the login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('a user without modules sees the empty state', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Ask the admin to enable a module')
        ->assertDontSee('data-module-card', false)
        ->assertDontSee('data-dashboard-greeting', false);
});

test('the empty state is translated', function () {
    $this->actingAs(User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]))
        ->get('/')
        ->assertSee('Peça ao admin para liberar um módulo');
});

test('the dashboard shows a card for each enabled module', function () {
    $user = User::factory()->withModules([Module::REMINDERS, Module::SHOPPING])->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtmlInOrder(['data-module-card="shopping"', 'data-module-card="reminders"'])
        ->assertDontSeeHtml('data-module-card="invoices"')
        ->assertDontSeeHtml('data-module-card="messaging"')
        ->assertDontSee('Ask the admin to enable a module');
});

test('a module card links to the module once its screens exist', function () {
    Route::middleware(['web', 'auth'])->get('/shopping', fn () => 'ok')->name('shopping.index');
    Route::getRoutes()->refreshNameLookups();

    $this->actingAs(User::factory()->withModules([Module::SHOPPING])->create())
        ->get('/')
        ->assertSee('href="'.url('/shopping').'"', false)
        ->assertSee('Open Shopping list');
});

test('the super admin sees every module', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/')
        ->assertSee('data-module-card="invoices"', false)
        ->assertSee('data-module-card="messaging"', false)
        ->assertSee('data-module-card="shopping"', false)
        ->assertSee('data-module-card="reminders"', false);
});

test('the greeting goes by the hour in the user timezone', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create(['name' => 'Ana Lima', 'timezone' => 'America/Sao_Paulo']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Good afternoon, Ana')
        ->assertSee('Wednesday, October 14, 2026');

    $this->travelTo(CarbonImmutable::parse('2026-10-15 01:00', 'UTC'));

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Good evening, Ana')
        ->assertSee('Wednesday, October 14, 2026');
});

test('each widget shows only with its module', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create();
    ShoppingListItem::factory()->for(ShoppingList::factory()->create(['name' => 'Market']), 'list')->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtml('data-dashboard-stat="shopping"')
        ->assertSeeHtml('data-dashboard-shopping')
        ->assertDontSeeHtml('data-dashboard-stat="unread-chats"')
        ->assertDontSeeHtml('data-dashboard-stat="today"')
        ->assertDontSeeHtml('data-dashboard-stat="overdue"')
        ->assertDontSeeHtml('data-dashboard-stat="invoices"')
        ->assertDontSeeHtml('data-dashboard-commitments')
        ->assertDontSeeHtml('data-dashboard-messages');

    $agendaOnly = User::factory()->withModules([Module::REMINDERS])->create();

    Livewire::actingAs($agendaOnly)
        ->test(Dashboard::class)
        ->assertSeeHtml('data-dashboard-commitments')
        ->assertSeeHtml('data-dashboard-stat="today"')
        ->assertDontSeeHtml('data-dashboard-shopping')
        ->assertDontSeeHtml('data-dashboard-stat="shopping"')
        ->assertDontSeeHtml('data-dashboard-messages');
});

test('the latest messages are the unread WhatsApp chats of the user numbers, newest first', function () {
    $user = User::factory()->withModules([Module::WHATSAPP])->create();
    $personal = WhatsappAccount::factory()->for($user)->create();
    $work = WhatsappAccount::factory()->for($user)->create();

    $older = WhatsappTarget::factory()->for($personal, 'account')->create(['name' => 'Bruno', 'unread_count' => 2, 'last_message_at' => now()->subHour()]);
    WhatsappChatMessage::factory()->for($older, 'target')->create(['body' => 'Are you coming?', 'sent_at' => now()->subHour()]);
    $newer = WhatsappTarget::factory()->for($work, 'account')->create(['name' => 'Carla', 'marked_unread' => true, 'last_message_at' => now()->subMinute(), 'ai_urgency' => 1]);
    $read = WhatsappTarget::factory()->for($personal, 'account')->create(['name' => 'Davi', 'last_message_at' => now()->subMinutes(5)]);
    $archived = WhatsappTarget::factory()->for($personal, 'account')->create(['name' => 'Elisa', 'unread_count' => 1, 'last_message_at' => now()->subMinutes(5), 'archived_at' => now()]);
    $someoneElses = WhatsappTarget::factory()->create(['name' => 'Fabio', 'unread_count' => 3, 'last_message_at' => now()->subMinutes(5)]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtmlInOrder(["data-dashboard-message=\"{$newer->friendly_id}\"", "data-dashboard-message=\"{$older->friendly_id}\""])
        ->assertSee('Are you coming?')
        ->assertSeeHtml('data-dashboard-message-unread="2"')
        ->assertSeeHtml('data-dashboard-ai-urgency="urgent"')
        ->assertSeeHtml(e(route('whatsapp.chat', ['account' => $personal->friendly_id, 'chat' => $older->friendly_id])))
        ->assertDontSeeHtml("data-dashboard-message=\"{$read->friendly_id}\"")
        ->assertDontSeeHtml("data-dashboard-message=\"{$archived->friendly_id}\"")
        ->assertDontSeeHtml("data-dashboard-message=\"{$someoneElses->friendly_id}\"")
        ->assertSeeHtml('data-dashboard-stat="unread-chats" data-count="2"');
});

test('unread Slack conversations join the latest messages, WhatsApp chats only once', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->inWorkspace($workspace, [Module::WHATSAPP, Module::CHATS])->create();
    $providers = [ChatProvider::idFor(ChatProvider::SLACK), ChatProvider::idFor(ChatProvider::WHATSAPP)];
    $workspace->chatProviders()->sync(array_fill_keys($providers, ['enabled_by_id' => $user->id]));
    $user->memberships()->sole()->chatProviders()->sync(array_fill_keys($providers, ['enabled_by_id' => $user->id]));
    $user->forgetMemberships();

    $target = WhatsappTarget::factory()->for(WhatsappAccount::factory()->for($user), 'account')
        ->create(['name' => 'Bruno', 'unread_count' => 1, 'last_message_at' => now()->subHour()]);
    $slack = SlackConversation::factory()->for(SlackAccount::factory()->for($user), 'account')->create();
    $unread = ChatConversation::factory()->forSlack($slack)->direct()->create(['name' => 'Ana Lima', 'unread_count' => 3, 'last_message_at' => now()->subMinute(), 'last_message' => ['text' => 'Hi there', 'from_me' => false, 'sender' => 'Ana Lima', 'kind' => 'text']]);
    $readChannel = ChatConversation::factory()->forSlack(SlackConversation::factory()->for($slack->account, 'account')->create())->create(['unread_count' => 0]);

    $html = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtmlInOrder(["data-dashboard-message=\"{$unread->friendly_id}\"", "data-dashboard-message=\"{$target->friendly_id}\""])
        ->assertSee('Hi there')
        ->assertSeeHtml(e(route('chats.chat', ['conversation' => $unread->friendly_id])))
        ->assertDontSeeHtml("data-dashboard-message=\"{$readChannel->friendly_id}\"")
        ->assertSeeHtml('data-dashboard-stat="unread-chats" data-count="2"')
        ->html();

    expect(substr_count($html, 'data-dashboard-message="'))->toBe(2);
});

test('next commitments show what is still to come and the overdue reminders as late', function () {
    $user = User::factory()->withModules([Module::REMINDERS])->create();

    $dentist = ReminderOccurrence::factory()->for(Reminder::factory()->create(['title' => 'Dentist']))->create(['due_at' => now()->addDays(2)]);
    ReminderOccurrence::factory()->for(Reminder::factory()->create(['title' => 'Old meeting']))->completed()->create(['due_at' => now()->subHours(2)]);
    ReminderOccurrence::factory()->for(Reminder::factory()->create(['title' => 'Far away']))->create(['due_at' => now()->addDays(10)]);
    $late = ReminderOccurrence::factory()->for(Reminder::factory()->create(['title' => 'Pay the rent']))->overdue()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtmlInOrder(["data-dashboard-overdue=\"{$late->friendly_id}\"", 'Late', "data-dashboard-next=\"{$dentist->friendly_id}\""])
        ->assertSee('Dentist')
        ->assertSee('Pay the rent')
        ->assertDontSee('Old meeting')
        ->assertDontSee('Far away')
        ->assertSeeHtml('data-dashboard-stat="overdue" data-count="1"')
        ->assertSeeHtml('data-dashboard-stat="today" data-count="1"');
});

test('next commitments say so when the agenda is empty', function () {
    Livewire::actingAs(User::factory()->withModules([Module::REMINDERS])->create())
        ->test(Dashboard::class)
        ->assertSeeHtml('data-dashboard-commitments-empty')
        ->assertSeeHtml('data-dashboard-stat="overdue" data-count="0"');
});

test('the tiles count the items to buy and the invoices awaiting action', function () {
    $user = User::factory()->withModules([Module::SHOPPING, Module::INVOICES])->create();

    $market = ShoppingList::factory()->create(['name' => 'Market']);
    ShoppingListItem::factory()->for($market, 'list')->count(2)->create();
    ShoppingListItem::factory()->for($market, 'list')->checked()->create();
    ShoppingListItem::factory()->for(ShoppingList::factory()->archived()->create(['name' => 'Old list']), 'list')->create();
    $done = ShoppingList::factory()->create(['name' => 'Bakery']);
    ShoppingListItem::factory()->for($done, 'list')->checked()->create();

    Invoice::factory()->pendingReview()->create();
    Invoice::factory()->failed()->create();
    Invoice::factory()->sent()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtml('data-dashboard-stat="shopping" data-count="2"')
        ->assertSeeHtml('data-dashboard-stat="invoices" data-count="2"')
        ->assertSeeHtml("data-dashboard-shopping-list=\"{$market->friendly_id}\"")
        ->assertSee('2 of 3 to pick')
        ->assertDontSeeHtml("data-dashboard-shopping-list=\"{$done->friendly_id}\"")
        ->assertDontSee('Old list');
});
