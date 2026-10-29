<?php

use App\Jobs\SyncWhatsappChats;
use App\Livewire\Whatsapp\AccountList;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();

    $this->stored = fn (string $id, string $remoteJid): array => [
        'id' => 'cuid-'.$id,
        'key' => ['remoteJid' => $remoteJid, 'fromMe' => false, 'id' => $id],
        'pushName' => 'Ana',
        'messageType' => 'conversation',
        'message' => ['conversation' => "message {$id}"],
        'messageTimestamp' => 1759700000,
    ];
});

test('syncing imports every page the Evolution API kept and asks for the full history', function () {
    $pages = [
        1 => [($this->stored)('M1', '5511911110000@s.whatsapp.net'), ($this->stored)('G1', '120363000000000001@g.us')],
        2 => [($this->stored)('M2', '5511922220000@s.whatsapp.net')],
    ];

    FakeEvolutionApi::install([
        'findMessages' => fn (Request $request) => Http::response(['messages' => [
            'total' => 3, 'pages' => 2, 'currentPage' => $request['page'], 'records' => $pages[$request['page']],
        ]]),
    ]);

    $account = WhatsappAccount::factory()->open()->for($this->owner)->create();

    SyncWhatsappChats::dispatchSync($account);

    expect(WhatsappChatMessage::query()->orderBy('external_id')->pluck('external_id')->all())->toBe(['G1', 'M1', 'M2'])
        ->and($account->fresh()->chats_synced_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');

    // The other settings are kept: `/settings/set` replaces them all.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/settings/set/'.$account->instance_name)
        && $request['syncFullHistory'] === true
        && $request['rejectCall'] === true
        && $request['msgCall'] === 'No calls, please');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/webhook/set/'.$account->instance_name));

    // Syncing again adds nothing.
    SyncWhatsappChats::dispatchSync($account);
    expect(WhatsappChatMessage::query()->count())->toBe(3);
});

test('the import goes on when the settings cannot be changed', function () {
    FakeEvolutionApi::install([
        'setSettings' => Http::response(['message' => 'boom'], 500),
        'findMessages' => ['messages' => ['total' => 1, 'pages' => 1, 'currentPage' => 1, 'records' => [($this->stored)('M1', '5511911110000@s.whatsapp.net')]]],
    ]);

    $account = WhatsappAccount::factory()->open()->for($this->owner)->create();

    SyncWhatsappChats::dispatchSync($account);

    expect(WhatsappChatMessage::query()->count())->toBe(1);
});

test('owners sync the conversations of a connected account from the list', function () {
    Queue::fake([SyncWhatsappChats::class]);

    $open = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $closed = WhatsappAccount::factory()->closed()->for($this->owner)->create(['nickname' => 'Work']);

    Livewire::actingAs($this->owner)
        ->test(AccountList::class)
        ->assertSeeHtml('data-account-sync-conversations="'.$open->friendly_id.'"')
        ->assertDontSeeHtml('data-account-sync-conversations="'.$closed->friendly_id.'"')
        ->call('syncConversations', $open->friendly_id)
        ->assertSet('error', null)
        ->call('syncConversations', $closed->friendly_id)
        ->assertSet('error', 'Work is not connected, so there is nothing to sync.');

    Queue::assertPushedOn('whatsapp', SyncWhatsappChats::class, fn (SyncWhatsappChats $job): bool => $job->account->is($open));
    Queue::assertPushed(SyncWhatsappChats::class, 1);
});
