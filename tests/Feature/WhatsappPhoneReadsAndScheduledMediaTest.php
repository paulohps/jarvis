<?php

use App\Ai\Agents\WhatsappReplyAgent;
use App\Livewire\Whatsapp\Chat;
use App\Livewire\Whatsapp\MessageForm;
use App\Models\AiProviderConnection;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Services\Whatsapp\WhatsappChatMedia;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
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

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});

test('what is read on the phone is read in jarvis, unless the number turns it off', function () {
    $read = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'IN1', 'sent_at' => now()->subMinutes(3)]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'IN2', 'sent_at' => now()->subMinute()]);
    $this->ana->update(['unread_count' => 2]);

    // Up to the first one: only the later message stays unread.
    ($this->postEvent)('messages.update', ['keyId' => 'IN1', 'remoteJid' => $this->ana->jid, 'fromMe' => false, 'status' => 'READ'])->assertOk();

    expect($this->ana->fresh()->unread_count)->toBe(1)
        ->and($this->ana->fresh()->read_up_to->equalTo($read->sent_at))->toBeTrue()
        ->and($read->fresh()->receipt)->toBeNull();

    // Turned off: Jarvis keeps its own count, WhatsApp still needs no receipt.
    $this->withToken($this->token)->patchJson(route('api.whatsapp.chat-settings.update', $this->account), ['syncs_phone_reads' => false])
        ->assertOk()
        ->assertJsonPath('data.chat_settings.syncs_phone_reads', false);

    ($this->postEvent)('messages.update', ['keyId' => 'IN2', 'remoteJid' => $this->ana->jid, 'fromMe' => false, 'status' => 'READ'])->assertOk();

    expect($this->ana->fresh()->unread_count)->toBe(1)
        ->and($this->ana->fresh()->read_up_to->equalTo(now()->subMinute()))->toBeTrue();

    // The web settings show and save it too.
    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('openSettings')
        ->assertSet('accountPrivacy.phone_reads', false)
        ->set('accountPrivacy.phone_reads', true)
        ->call('savePrivacy');

    expect($this->account->fresh()->syncs_phone_reads)->toBeTrue();
});

test('the ai writes a new message from the prompt alone, without the chat', function () {
    AiProviderConnection::factory()->active()->create();
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Segredo da conversa']);
    WhatsappReplyAgent::fake(['Oi Ana! Feliz aniversário!'])->preventStrayPrompts();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.draft', $this->ana), ['without_context' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('instruction');

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.draft', $this->ana), ['without_context' => true, 'instruction' => 'deseja feliz aniversário'])
        ->assertOk()
        ->assertExactJson(['text' => 'Oi Ana! Feliz aniversário!']);

    WhatsappReplyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'deseja feliz aniversário')
        && str_contains($prompt->prompt, 'write a new message')
        && ! str_contains($prompt->prompt, 'Segredo da conversa')
        && ! str_contains((string) $prompt->agent->instructions(), 'Segredo da conversa'));
});

test('a scheduled message sends its file, the text its caption, and keeps the file while it may run again', function () {
    $message = $this->withToken($this->token)->post(route('api.whatsapp.conversations.schedule', $this->ana), [
        'text' => 'Olha a foto',
        'at' => '2026-10-07T09:00',
        'file' => UploadedFile::fake()->image('praia.jpg', 20, 20),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.media.mime', 'image/jpeg')
        ->assertJsonPath('data.media.name', 'praia.jpg')
        ->json('data.friendly_id');

    $scheduled = WhatsappMessage::query()->where('friendly_id', $message)->sole();
    expect(WhatsappChatMedia::disk()->exists($scheduled->media_path))->toBeTrue();

    $scheduled->runScheduledSlot(CarbonImmutable::parse('2026-10-07 09:00', 'UTC'));

    $delivery = WhatsappDelivery::query()->sole();
    expect($delivery->media_path)->toBe($scheduled->media_path);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendMedia/jarvis')
        && $request['mediatype'] === 'image'
        && $request['caption'] === 'Olha a foto');

    // Nobody opened it, but the scheduled message still sends it.
    $this->artisan('whatsapp:prune-media', ['--days' => 1])->assertSuccessful();
    expect(WhatsappChatMedia::disk()->exists($scheduled->media_path))->toBeTrue();
});

test('a voice note is scheduled without text, recorded in the browser', function () {
    $voice = UploadedFile::fake()->createWithContent('voice-note.webm', "\x1A\x45\xDF\xA3webm")->mimeType('audio/webm');

    $this->withToken($this->token)->post(route('api.whatsapp.conversations.schedule', $this->ana), ['text' => 'Oi', 'at' => '2026-10-07T09:00', 'file' => $voice], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('text');

    $this->withToken($this->token)->post(route('api.whatsapp.conversations.schedule', $this->ana), ['at' => '2026-10-07T09:00', 'file' => $voice], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.media.mime', 'audio/webm');

    WhatsappMessage::query()->sole()->runScheduledSlot(CarbonImmutable::parse('2026-10-07 09:00', 'UTC'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendWhatsAppAudio/jarvis'));
});

test('the web schedules a file from the chat and keeps, replaces or removes it on the message form', function () {
    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('startSchedule')
        ->assertSeeHtml('data-media-picker-record')
        ->set('scheduleFile', UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf'))
        ->set('scheduleRecurrence.starts_at', '2026-10-07T09:00')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-chat-scheduled-media');

    $scheduled = WhatsappMessage::query()->sole();
    $firstPath = $scheduled->media_path;

    expect($scheduled->media_mime)->toBe('application/pdf')
        ->and($scheduled->body)->toBe('');

    $form = Livewire::actingAs($this->owner)
        ->test(MessageForm::class, ['message' => $scheduled])
        ->assertSeeHtml('data-media-picker-file')
        ->set('body', 'Segue o contrato')
        ->call('save')
        ->assertHasNoErrors();

    expect($scheduled->fresh()->media_path)->toBe($firstPath)
        ->and($scheduled->fresh()->body)->toBe('Segue o contrato');

    $form->set('file', UploadedFile::fake()->image('assinatura.png', 10, 10))->call('save')->assertHasNoErrors();

    expect($scheduled->fresh()->media_mime)->toBe('image/png')
        ->and(WhatsappChatMedia::disk()->exists($firstPath))->toBeFalse();

    $form->call('removeMedia')->call('save')->assertHasNoErrors();

    expect($scheduled->fresh()->media_path)->toBeNull();

    $form->set('body', '')->call('save')->assertHasErrors('body');
});
