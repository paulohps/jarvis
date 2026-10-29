<?php

use App\Actions\Whatsapp\ApplyWhatsappChatsBulkAction;
use App\Events\WhatsappChatUpdated;
use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();

    $this->chatWith = function (string $name, array $attributes = []): WhatsappTarget {
        $chat = WhatsappTarget::factory()->contact()->create([
            'whatsapp_account_id' => $this->account->id,
            'name' => $name,
            'last_message_at' => now()->subHour(),
            ...$attributes,
        ]);
        WhatsappChatMessage::factory()->for($chat, 'target')->create(['sent_at' => now()->subHour()]);

        return $chat;
    };
});

test('the chats picked are read at once, and what the AI found about them goes', function () {
    $ana = ($this->chatWith)('Ana', ['unread_count' => 3, 'ai_urgency' => 1, 'priority' => 2]);
    $bruno = ($this->chatWith)('Bruno', ['marked_unread' => true]);
    $carla = ($this->chatWith)('Carla', ['unread_count' => 1]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->assertSeeHtml('data-chat-bulk-action="read"')
        ->call('bulkAction', 'read', [$ana->friendly_id, $bruno->friendly_id])
        ->assertSet('notice', 'Done in 2 chats.')
        ->assertDontSeeInIsland('data-chat-unread="'.$ana->friendly_id.'"');

    expect($ana->fresh())->unread_count->toBe(0)->ai_urgency->toBeNull()->priority->toBe(2)
        ->and($bruno->fresh()->marked_unread)->toBeFalse()
        ->and($carla->fresh()->unread_count)->toBe(1);
});

test('the chats picked are archived and marked unread on the phone too, the list reloading once', function () {
    Event::fake([WhatsappChatUpdated::class]);
    $ana = ($this->chatWith)('Ana');
    $bruno = ($this->chatWith)('Bruno');

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->call('bulkAction', 'unread', [$ana->friendly_id, $bruno->friendly_id])
        ->assertSet('chatId', null);

    expect($ana->fresh()->marked_unread)->toBeTrue()->and($bruno->fresh()->marked_unread)->toBeTrue();
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/chat/markChatUnread/'));

    $screen->call('bulkAction', 'archive', [$ana->friendly_id, $bruno->friendly_id])
        ->assertSet('notice', 'Done in 2 chats.');

    expect($ana->fresh()->isArchived())->toBeTrue()->and($bruno->fresh()->isArchived())->toBeTrue();
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/chat/archiveChat/'));
    Event::assertNotDispatched(WhatsappChatUpdated::class);

    $screen->set('showArchived', true)
        ->assertSeeInIsland('data-chat-bulk-action="unarchive"')
        ->call('bulkAction', 'unarchive', [$ana->friendly_id]);

    expect($ana->fresh()->isArchived())->toBeFalse()->and($bruno->fresh()->isArchived())->toBeTrue();
});

test('the chats picked are muted, and lose the user\'s priority or the AI\'s, one apart from the other', function () {
    $ana = ($this->chatWith)('Ana', ['unread_count' => 1, 'priority' => 1, 'ai_urgency' => 2, 'ai_summary' => 'Quer o orçamento.']);
    $bruno = ($this->chatWith)('Bruno', ['priority' => 4]);

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('bulkAction', 'mute', [$ana->friendly_id, $bruno->friendly_id]);

    expect($ana->fresh()->isMuted())->toBeTrue()->and($bruno->fresh()->isMuted())->toBeTrue();

    $screen->call('bulkAction', 'clear_ai', [$ana->friendly_id]);
    expect($ana->fresh())->ai_urgency->toBeNull()->ai_summary->toBeNull()->priority->toBe(1);

    $screen->call('bulkAction', 'clear_priority', [$ana->friendly_id, $bruno->friendly_id])
        ->call('bulkAction', 'unmute', [$ana->friendly_id]);
    expect($ana->fresh())->priority->toBeNull()->isMuted()->toBeFalse()
        ->and($bruno->fresh())->priority->toBeNull()->isMuted()->toBeTrue();
});

test('a chat WhatsApp refuses is counted apart, and the others go on', function () {
    $ana = ($this->chatWith)('Ana');
    $bruno = ($this->chatWith)('Bruno');
    FakeEvolutionApi::install(['archiveChat' => Http::sequence()->push(['error' => 'boom'], 500)->push(['archived' => true])]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('bulkAction', 'archive', [$ana->friendly_id, $bruno->friendly_id])
        ->assertSet('error', 'WhatsApp did not take 1 chat. Try it again in a moment.');

    expect(collect([$ana->fresh(), $bruno->fresh()])->filter->isArchived())->toHaveCount(1);
});

test('nothing is done to too many chats, to an unknown action, nor to another user\'s number', function () {
    $ana = ($this->chatWith)('Ana', ['unread_count' => 1]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('bulkAction', 'read', array_map(fn (int $index): string => "wtgt_{$index}", range(1, ApplyWhatsappChatsBulkAction::MAX_CHATS + 1)))
        ->assertSet('error', 'Up to 50 chats at a time.')
        ->call('bulkAction', 'delete', [$ana->friendly_id])
        ->assertSet('error', 'The selected action is invalid.');

    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();

    expect(fn () => app(ApplyWhatsappChatsBulkAction::class)($stranger, $this->account, 'read', [$ana->friendly_id]))
        ->toThrow(AuthorizationException::class)
        ->and($ana->fresh()->unread_count)->toBe(1);
});
