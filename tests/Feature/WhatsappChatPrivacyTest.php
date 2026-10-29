<?php

use App\Actions\Whatsapp\SendWhatsappReadReceipts;
use App\Actions\Whatsapp\SetWhatsappChatPrivacy;
use App\Jobs\SendWhatsappPresence;
use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()]);
});

/**
 * @return list<Request>
 */
function readReceiptsSent(): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/chat/markMessageAsRead/'))->map(fn (array $pair): Request => $pair[0])->values()->all();
}

test('the chat is read in jarvis only until read receipts are turned on', function () {
    $old = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'OLD', 'sent_at' => now()->subHours(2)]);
    WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'MINE']);

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('sendReadReceipts');

    expect(readReceiptsSent())->toBe([]);

    // On for the number: the contact's messages are reported read, once.
    $screen->call('openSettings')
        ->assertSet('accountPrivacy', ['read_receipts' => false, 'typing' => false, 'notifications' => true, 'phone_reads' => true])
        ->assertSet('chatPrivacy', ['read_receipts' => Chat::FOLLOW_ACCOUNT, 'typing' => Chat::FOLLOW_ACCOUNT, 'notifications' => Chat::FOLLOW_ACCOUNT])
        ->set('accountPrivacy.read_receipts', true)
        ->call('savePrivacy')
        ->call('sendReadReceipts')
        ->call('sendReadReceipts');

    expect(readReceiptsSent())->toHaveCount(1)
        ->and(readReceiptsSent()[0]['readMessages'])->toBe([['id' => 'OLD', 'fromMe' => false, 'remoteJid' => '5511911110000@s.whatsapp.net']])
        ->and($this->ana->fresh()->read_up_to->equalTo($old->sent_at))->toBeTrue();

    // A newer message is reported; the chat turned off overrides the number.
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'NEW', 'sent_at' => now()]);

    $screen->call('sendReadReceipts');

    expect(readReceiptsSent())->toHaveCount(2)
        ->and(readReceiptsSent()[1]['readMessages'][0]['id'])->toBe('NEW');

    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'NEWER', 'sent_at' => now()->addSecond()]);

    $screen->call('openSettings')
        ->set('chatPrivacy.read_receipts', 'off')
        ->set('chatPrivacy.notifications', 'off')
        ->set('accountPrivacy.notifications', false)
        ->call('savePrivacy')
        ->call('sendReadReceipts');

    expect(readReceiptsSent())->toHaveCount(2)
        ->and($this->ana->fresh()->sends_read_receipts)->toBeFalse()
        ->and($this->ana->fresh()->notifies_messages)->toBeFalse()
        ->and($this->account->fresh()->notifies_messages)->toBeFalse()
        ->and($this->account->fresh()->sends_read_receipts)->toBeTrue();
});

test('a chat turned on sends read receipts while its number does not', function () {
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'HI']);
    $bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno']);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $bruno->id]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('openSettings')
        ->set('chatPrivacy.read_receipts', 'on')
        ->call('savePrivacy')
        ->call('sendReadReceipts')
        ->call('openChat', $bruno->friendly_id)
        ->call('sendReadReceipts');

    expect(readReceiptsSent())->toHaveCount(1)
        ->and(readReceiptsSent()[0]['readMessages'][0]['id'])->toBe('HI');
});

test('a failing whatsapp keeps the messages to report on the next look', function () {
    FakeEvolutionApi::install(['markRead' => Http::response(['status' => 500], 500)]);
    $this->account->update(['sends_read_receipts' => true]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id]);

    expect(app(SendWhatsappReadReceipts::class)($this->owner, $this->ana->fresh()))->toBe(0)
        ->and($this->ana->fresh()->read_up_to)->toBeNull();
});

test('typing shows on whatsapp only when the chat sends typing', function () {
    Bus::fake();

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertSeeHtml("send: 'send', typing: false")
        ->call('typing');

    Bus::assertNotDispatchedAfterResponse(SendWhatsappPresence::class, fn (SendWhatsappPresence $job): bool => $job->presence === 'composing');

    $screen->call('openSettings')
        ->set('chatPrivacy.typing', 'on')
        ->call('savePrivacy')
        ->assertSeeHtml("send: 'send', typing: true")
        ->call('typing');

    Bus::assertDispatchedAfterResponse(SendWhatsappPresence::class, fn (SendWhatsappPresence $job): bool => $job->jid === '5511911110000@s.whatsapp.net'
        && $job->presence === 'composing'
        && $job->account->is($this->account));
});

test('the presence call lets the evolution api finish the presence by itself', function () {
    (new SendWhatsappPresence($this->account, '5511911110000@s.whatsapp.net', 'composing', 6000))->handle(app(EvolutionApiClient::class));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/sendPresence/jarvis')
        && $request->data() === ['number' => '5511911110000@s.whatsapp.net', 'presence' => 'composing', 'delay' => 6000]);
});

test('only the owner changes the privacy of a number', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();

    expect(fn () => app(SetWhatsappChatPrivacy::class)->forAccount($stranger, $this->account, true, true))
        ->toThrow(AuthorizationException::class);

    expect($this->account->fresh()->sends_read_receipts)->toBeFalse();
});
