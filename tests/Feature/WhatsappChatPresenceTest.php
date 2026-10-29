<?php

use App\Events\WhatsappChatPresenceChanged;
use App\Jobs\SendWhatsappPresence;
use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = WhatsappTarget::factory()->contact()->create([
        'whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'lid' => '81234567890123@lid', 'last_message_at' => now(),
    ]);

    $this->postPresence = fn (string $chatJid, array $presences) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => 'PRESENCE_UPDATE', 'instance' => $this->account->instance_name, 'data' => ['id' => $chatJid, 'presences' => $presences]],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});

test('a contact typing or online reaches the owner screen, by phone jid or lid', function () {
    Event::fake([WhatsappChatPresenceChanged::class]);

    ($this->postPresence)('81234567890123@lid', ['81234567890123@lid' => ['lastKnownPresence' => 'composing']])
        ->assertOk()->assertJson(['status' => 'handled']);
    ($this->postPresence)('5511911110000@s.whatsapp.net', ['5511911110000@s.whatsapp.net' => ['lastKnownPresence' => 'unavailable', 'lastSeen' => 1791200000]])
        ->assertOk();

    Event::assertDispatched(WhatsappChatPresenceChanged::class, fn (WhatsappChatPresenceChanged $event): bool => $event->userId === $this->owner->id
        && $event->chatId === $this->ana->friendly_id
        && $event->presence === 'composing'
        && $event->who === null);
    Event::assertDispatched(WhatsappChatPresenceChanged::class, fn (WhatsappChatPresenceChanged $event): bool => $event->presence === 'unavailable'
        && $event->lastSeen !== null);

    expect((new WhatsappChatPresenceChanged($this->owner->id, 'a', 'c', 'composing'))->broadcastAs())->toBe('whatsapp.chat.presence');
});

test('in a group the participant recording is named', function () {
    Event::fake([WhatsappChatPresenceChanged::class]);
    $group = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '120363000000000001@g.us']);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $group->id, 'sender_jid' => '5511955550000@s.whatsapp.net', 'sender_name' => 'Carla']);

    ($this->postPresence)('120363000000000001@g.us', [
        '81234567890123@lid' => ['lastKnownPresence' => 'recording'],
        '5511955550000@s.whatsapp.net' => ['lastKnownPresence' => 'composing'],
        '5511966660000@s.whatsapp.net' => ['lastKnownPresence' => 'composing'],
        '5511977770000@s.whatsapp.net' => ['lastKnownPresence' => 'dancing'],
    ])->assertOk();

    Event::assertDispatchedTimes(WhatsappChatPresenceChanged::class, 3);
    Event::assertDispatched(WhatsappChatPresenceChanged::class, fn (WhatsappChatPresenceChanged $event): bool => $event->presence === 'recording' && $event->who === 'Ana');
    Event::assertDispatched(WhatsappChatPresenceChanged::class, fn (WhatsappChatPresenceChanged $event): bool => $event->who === 'Carla');
    Event::assertDispatched(WhatsappChatPresenceChanged::class, fn (WhatsappChatPresenceChanged $event): bool => $event->who === '+5511966660000');
});

test('the presence of an unknown chat is ignored', function () {
    Event::fake([WhatsappChatPresenceChanged::class]);

    ($this->postPresence)('5511900000000@s.whatsapp.net', ['5511900000000@s.whatsapp.net' => ['lastKnownPresence' => 'composing']])
        ->assertOk()->assertJson(['status' => 'ignored']);

    Event::assertNotDispatched(WhatsappChatPresenceChanged::class);
});

test('opening a chat asks whatsapp for its presence, unseen by the contact', function () {
    FakeEvolutionApi::install();
    Bus::fake();
    $bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'jid' => '5511922220000@s.whatsapp.net']);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('openChat', $bruno->friendly_id);

    foreach (['5511911110000@s.whatsapp.net', '5511922220000@s.whatsapp.net'] as $jid) {
        Bus::assertDispatchedAfterResponse(SendWhatsappPresence::class, fn (SendWhatsappPresence $job): bool => $job->jid === $jid
            && $job->presence === 'paused'
            && $job->milliseconds === 0);
    }
});
