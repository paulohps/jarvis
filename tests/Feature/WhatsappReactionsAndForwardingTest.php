<?php

use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReaction;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Services\Whatsapp\WhatsappChatMedia;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    Storage::fake((string) config('filesystems.private'));
    FakeEvolutionApi::install();
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()]);
    $this->bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'jid' => '5511922220000@s.whatsapp.net', 'last_message_at' => now()->subHour()]);
    $this->message = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'IN1', 'body' => 'Vamos?']);

    $this->postEvent = fn (array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => 'messages.upsert', 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});

test('reactions from whatsapp are kept, replaced and removed, one per person', function () {
    $reaction = fn (string $emoji, bool $fromMe, int $ms) => [
        'key' => ['remoteJid' => $this->ana->jid, 'fromMe' => $fromMe, 'id' => 'R'.$ms],
        'messageType' => 'reactionMessage',
        'message' => ['reactionMessage' => ['key' => ['remoteJid' => $this->ana->jid, 'fromMe' => false, 'id' => 'IN1'], 'text' => $emoji, 'senderTimestampMs' => $ms]],
        'messageTimestamp' => intdiv($ms, 1000),
    ];
    $now = now()->getTimestampMs();

    ($this->postEvent)($reaction('❤️', false, $now))->assertOk();
    ($this->postEvent)($reaction('👍', true, $now))->assertOk();
    // An older one arriving late does not undo a newer one.
    ($this->postEvent)($reaction('😮', false, $now - 5000))->assertOk();

    expect($this->message->reactions()->pluck('emoji', 'reactor')->all())->toBe([$this->ana->jid => '❤️', WhatsappChatReaction::ME => '👍'])
        ->and(WhatsappChatMessage::query()->count())->toBe(1);

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->ana))
        ->assertJsonPath('data.0.reactions', [['emoji' => '❤️', 'count' => 1, 'mine' => false], ['emoji' => '👍', 'count' => 1, 'mine' => true]]);

    ($this->postEvent)($reaction('', false, $now + 1000))->assertOk();

    expect($this->message->reactions()->pluck('emoji')->all())->toBe(['👍']);
});

test('the owner reacts from the app and the web, and the same emoji again removes it', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->withToken($stranger->createToken('Pixel')->plainTextToken)
        ->postJson(route('api.whatsapp.conversations.react', [$this->ana, $this->message->friendly_id]), ['emoji' => '👍'])
        ->assertForbidden();
    app('auth')->forgetGuards();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.react', [$this->ana, $this->message->friendly_id]), ['emoji' => '🙏'])
        ->assertOk()
        ->assertExactJson(['emoji' => '🙏']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendReaction/jarvis')
        && $request['key'] === ['remoteJid' => $this->ana->jid, 'fromMe' => false, 'id' => 'IN1']
        && $request['reaction'] === '🙏');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertSeeHtml('data-chat-reaction-mine')
        ->call('react', $this->message->friendly_id, '🙏')
        ->assertDontSeeHtml('data-chat-reactions="'.$this->message->friendly_id.'"');

    expect($this->message->reactions()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendReaction/jarvis') && $request['reaction'] === '');

});

test('messages are forwarded to other chats of the number, texts and files, in order', function () {
    $photo = WhatsappChatMessage::factory()->create([
        'whatsapp_target_id' => $this->ana->id, 'external_id' => 'IMG1', 'message_type' => 'imageMessage', 'body' => 'A praia',
        'media_mime' => 'image/png', 'sent_at' => now()->subMinutes(30),
    ]);
    $family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Família']);

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.forward', $this->ana), [
        'messages' => [$this->message->friendly_id, $photo->friendly_id],
        'to' => [$this->bruno->friendly_id, $family->friendly_id],
    ])->assertAccepted()->assertExactJson(['deliveries' => 4]);

    $deliveries = WhatsappDelivery::query()->orderBy('id')->get();
    expect($deliveries->pluck('body')->all())->toBe(['Vamos?', 'A praia', 'Vamos?', 'A praia'])
        ->and($deliveries->pluck('whatsapp_target_id')->unique()->values()->all())->toBe([$this->bruno->id, $family->id])
        ->and($deliveries[1]->media_path)->not->toBe($deliveries[3]->media_path)
        ->and(WhatsappChatMedia::disk()->exists($deliveries[1]->media_path))->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendMedia/jarvis') && $request['caption'] === 'A praia');

    // Another number's chat, or a deleted message, is refused.
    $elsewhere = WhatsappTarget::factory()->contact()->create();
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.forward', $this->ana), ['messages' => [$this->message->friendly_id], 'to' => [$elsewhere->friendly_id]])
        ->assertUnprocessable()->assertJsonValidationErrors('to');

    $this->message->update(['revoked_at' => now()]);
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.forward', $this->ana), ['messages' => [$this->message->friendly_id], 'to' => [$this->bruno->friendly_id]])
        ->assertUnprocessable()->assertJsonValidationErrors('messages');
});

test('the web forwards the selected messages to the chosen chats', function () {
    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('toggleSelected', $this->message->friendly_id)
        ->assertSeeHtml('data-chat-forward-selected')
        ->call('startForward')
        ->assertSet('showForward', true)
        ->set('forwardSearch', 'Bru')
        ->assertSeeHtml('data-chat-forward-to="'.$this->bruno->friendly_id.'"')
        ->set('forwardTo', [$this->bruno->friendly_id])
        ->call('forward')
        ->assertHasNoErrors()
        ->assertSet('showForward', false)
        ->assertSet('selected', []);

    expect(WhatsappDelivery::query()->sole()->whatsapp_target_id)->toBe($this->bruno->id);
});
