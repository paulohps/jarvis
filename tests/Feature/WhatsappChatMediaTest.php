<?php

use App\Actions\Whatsapp\SendWhatsappChatMessage;
use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
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
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = '5511911110000@s.whatsapp.net';

    $this->media = fn (string $id, string $type, array $node, array $extra = []): array => [
        'key' => ['remoteJid' => $this->ana, 'fromMe' => false, 'id' => $id],
        'pushName' => 'Ana',
        'messageType' => $type,
        'message' => [$type => $node, ...$extra],
        'messageTimestamp' => now()->subMinute()->getTimestamp(),
    ];

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});

test('a media is only described until the owner loads it, then shows in the chat and can be downloaded', function () {
    ($this->postEvent)('messages.upsert', ($this->media)('IMG1', 'imageMessage', ['mimetype' => 'image/png', 'caption' => 'Olha isso', 'fileLength' => '68'], ['base64' => FakeEvolutionApi::MEDIA_BASE64]))
        ->assertOk();

    $message = WhatsappChatMessage::query()->sole();
    expect($message->mediaKind())->toBe('image')
        ->and($message->body)->toBe('Olha isso')
        ->and($message->only('media_mime', 'media_size', 'media_path'))->toBe(['media_mime' => 'image/png', 'media_size' => 68, 'media_path' => null]);
    Http::assertNothingSent();

    $mediaUrl = route('whatsapp.chat.media', $message);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $message->target->friendly_id])
        ->assertSeeHtml('data-chat-media="image"')
        ->assertSeeHtml('data-chat-load="'.$message->friendly_id.'"')
        ->assertDontSeeHtml('<img src="'.$mediaUrl.'"')
        ->assertDontSeeHtml('data-chat-download=')
        ->call('loadMedia', $message->friendly_id)
        ->assertSet('error', null)
        ->assertSeeHtml('<img src="'.$mediaUrl.'"')
        ->assertSeeHtml('data-chat-download="'.$message->friendly_id.'"')
        ->assertDontSeeHtml('data-chat-load=')
        ->call('loadMedia', $message->friendly_id);

    // Opening the chat also asks for its presence (WatchWhatsappChatPresence).
    expect(Http::recorded(fn (Request $request): bool => ! str_contains($request->url(), '/chat/sendPresence/')))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/getBase64FromMediaMessage/') && $request['message']['key']['id'] === 'IMG1');
    expect(WhatsappChatMedia::disk()->get($message->fresh()->media_path))->toBe(base64_decode(FakeEvolutionApi::MEDIA_BASE64));

    $this->actingAs(User::factory()->withModules([Module::WHATSAPP])->create())
        ->get($mediaUrl)
        ->assertForbidden();

    $inline = $this->actingAs($this->owner)->get($mediaUrl)->assertOk();
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline');

    $this->actingAs($this->owner)
        ->get($mediaUrl.'?download=1')
        ->assertOk()
        ->assertDownload();

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/chat/getBase64FromMediaMessage/')))->toHaveCount(1);
});

test('a file too big stays on the phone, and WhatsApp failing to hand it over is a 502', function () {
    ($this->postEvent)('messages.set', [
        ($this->media)('BIG1', 'videoMessage', ['mimetype' => 'video/mp4', 'fileLength' => ['low' => 0, 'high' => 1]]),
        ($this->media)('AUD1', 'audioMessage', ['mimetype' => 'audio/ogg; codecs=opus']),
    ])->assertOk();

    $big = WhatsappChatMessage::query()->where('external_id', 'BIG1')->sole();
    expect($big->media_size)->toBe(4294967296)->and($big->canDownloadMedia())->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $big->target->friendly_id])
        ->assertDontSeeHtml('data-chat-download="'.$big->friendly_id.'"')
        ->assertSee('Too big. See it on the phone.');

    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $big))->assertNotFound();

    FakeEvolutionApi::install(['mediaOf' => Http::response(['message' => 'not found'], 400)]);
    $audio = WhatsappChatMessage::query()->where('external_id', 'AUD1')->sole();

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $audio->target->friendly_id])
        ->call('loadMedia', $audio->friendly_id)
        ->assertSet('error', 'WhatsApp could not hand over the file. Try again in a moment.')
        ->assertSeeHtml('data-chat-load="'.$audio->friendly_id.'"');

    $this->actingAs($this->owner)
        ->get(route('whatsapp.chat.media', $audio))
        ->assertStatus(502);
});

test('a document keeps its name and caption, and downloads under that name', function () {
    ($this->postEvent)('messages.upsert', ($this->media)('DOC1', 'documentWithCaptionMessage', ['message' => ['documentMessage' => [
        'mimetype' => 'application/pdf', 'fileName' => 'orcamento.pdf', 'caption' => 'Segue', 'fileLength' => 2048,
    ]]]))->assertOk();

    $document = WhatsappChatMessage::query()->sole();
    expect($document->mediaKind())->toBe('document')
        ->and($document->only('media_name', 'body', 'media_size'))->toBe(['media_name' => 'orcamento.pdf', 'body' => 'Segue', 'media_size' => 2048]);

    $this->actingAs($this->owner)
        ->get(route('whatsapp.chat.media', $document).'?download=1')
        ->assertOk()
        ->assertDownload('orcamento.pdf');

    expect($document->fresh()->media_path)->toEndWith('.pdf');
});

test('a file attached on the screen goes out as a media, the text its caption, and stays in the chat', function () {
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => $this->ana, 'last_message_at' => now()]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $chat->friendly_id])
        ->set('attachment', UploadedFile::fake()->image('foto.png', 10, 10))
        ->assertSeeHtml('data-chat-attachment')
        ->set('text', 'Olha a foto')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('attachment', null);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendMedia/')
        && $request['mediatype'] === 'image'
        && $request['mimetype'] === 'image/png'
        && $request['caption'] === 'Olha a foto'
        && $request['number'] === $this->ana
        && base64_decode($request['media'], true) !== false);

    $delivery = WhatsappDelivery::query()->sole();
    $sent = WhatsappChatMessage::query()->sole();
    expect($sent->message_type)->toBe('imageMessage')
        ->and($sent->from_me)->toBeTrue()
        ->and($sent->body)->toBe('Olha a foto')
        ->and($sent->media_path)->toBe($delivery->media_path)
        ->and(WhatsappChatMedia::disk()->exists($sent->media_path))->toBeTrue();
});

test('an audio goes out as a voice note and a file of another type as a document', function () {
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => $this->ana, 'last_message_at' => now()]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $chat->friendly_id])
        ->set('attachment', UploadedFile::fake()->createWithContent('nota.mp3', 'ID3audio')->mimeType('audio/mpeg'))
        ->call('send')
        ->assertHasNoErrors()
        ->set('attachment', UploadedFile::fake()->create('planilha.csv', 1, 'text/csv'))
        ->call('send')
        ->assertHasNoErrors()
        ->set('attachment', UploadedFile::fake()->create('enorme.zip', SendWhatsappChatMessage::MAX_FILE_KILOBYTES + 1, 'application/zip'))
        ->assertHasErrors('attachment');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendWhatsAppAudio/'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendMedia/')
        && $request['mediatype'] === 'document'
        && $request['fileName'] === 'planilha.csv');
});

test('an opened chat asks WhatsApp for its picture at most once a day, and shows it', function () {
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => $this->ana, 'last_message_at' => now()]);

    $component = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $chat->friendly_id])
        ->assertSeeHtml('$wire.refreshPicture(')
        ->assertDontSeeHtml('data-chat-picture')
        ->call('refreshPicture', $chat->friendly_id)
        ->assertSeeHtml('src="'.e(FakeEvolutionApi::PICTURE_URL).'"')
        ->assertDontSeeHtml('$wire.refreshPicture(')
        ->call('refreshPicture', $chat->friendly_id);

    // Opening the chat also asks for its presence (WatchWhatsappChatPresence).
    expect(Http::recorded(fn (Request $request): bool => ! str_contains($request->url(), '/chat/sendPresence/')))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/fetchProfilePictureUrl/') && $request['number'] === $this->ana);

    $this->travel(25)->hours();
    FakeEvolutionApi::install(['profilePicture' => ['wuid' => $this->ana, 'profilePictureUrl' => null]]);
    $component->call('refreshPicture', $chat->friendly_id)->assertDontSeeHtml('data-chat-picture');

    expect($chat->fresh()->picture_url)->toBeNull();
});

test('an audio comes converted to MP4 so Safari plays it, and one kept in Ogg is swapped once', function () {
    ($this->postEvent)('messages.set', [
        ($this->media)('AUD1', 'audioMessage', ['mimetype' => 'audio/ogg; codecs=opus', 'fileLength' => 14511]),
        ($this->media)('AUD2', 'audioMessage', ['mimetype' => 'audio/ogg; codecs=opus']),
    ])->assertOk();
    FakeEvolutionApi::install(['mediaOf' => ['mediaType' => 'audioMessage', 'mimetype' => 'audio/mp4', 'base64' => base64_encode('mp4-audio')]]);

    $fresh = WhatsappChatMessage::query()->where('external_id', 'AUD1')->sole();
    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $fresh))->assertOk()->assertHeader('Content-Type', 'audio/mp4');

    Http::assertSent(fn (Request $request): bool => $request['message']['key']['id'] === 'AUD1' && $request['convertToMp4'] === true);
    expect($fresh->fresh()->only('media_mime', 'media_size'))->toBe(['media_mime' => 'audio/mp4', 'media_size' => 9])
        ->and($fresh->fresh()->media_path)->toEndWith('.m4a');

    // Kept in Ogg before the conversion: fetched again once, the Ogg removed.
    $old = WhatsappChatMessage::query()->where('external_id', 'AUD2')->sole();
    WhatsappChatMedia::store($old, 'ogg-audio');
    $oggPath = $old->fresh()->media_path;

    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $old))->assertOk();
    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $old))->assertOk();

    expect(WhatsappChatMedia::disk()->exists($oggPath))->toBeFalse()
        ->and(WhatsappChatMedia::disk()->get($old->fresh()->media_path))->toBe('mp4-audio');
    Http::assertSentCount(2);

    // WhatsApp no longer handing an Ogg over keeps it playing where it can.
    $older = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $old->whatsapp_target_id, 'message_type' => 'audioMessage', 'media_mime' => 'audio/ogg']);
    WhatsappChatMedia::store($older, 'ogg-audio');
    FakeEvolutionApi::install(['mediaOf' => Http::response(['message' => 'not found'], 400)]);

    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $older))->assertOk()->assertHeader('Content-Type', 'audio/ogg');
});

test('files nobody opened for a while are removed, and load again when asked for', function () {
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => $this->ana, 'last_message_at' => now()]);
    $stored = function (string $id) use ($chat): WhatsappChatMessage {
        $message = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $chat->id, 'external_id' => $id, 'message_type' => 'imageMessage', 'media_mime' => 'image/png']);
        WhatsappChatMedia::store($message, "png-{$id}");

        return $message->fresh();
    };

    $stale = $stored('OLD');
    $outgoing = $stored('OUT');
    WhatsappDelivery::factory()->create(['media_path' => $outgoing->media_path]);
    $this->travel(31)->days();
    $opened = $stored('NEW');

    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $opened))->assertOk();

    $this->artisan('whatsapp:prune-media')->expectsOutputToContain('Removed 1 WhatsApp chat files not opened for 30 days.')->assertSuccessful();

    expect(WhatsappChatMedia::disk()->exists($stale->media_path))->toBeFalse()
        ->and($stale->fresh()->only('media_path', 'media_accessed_at'))->toBe(['media_path' => null, 'media_accessed_at' => null])
        ->and(WhatsappChatMedia::disk()->exists($outgoing->media_path))->toBeTrue()
        ->and($opened->fresh()->media_path)->not->toBeNull();

    // Asked for again, it is fetched again from WhatsApp.
    $this->actingAs($this->owner)->get(route('whatsapp.chat.media', $stale))->assertOk();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/getBase64FromMediaMessage/') && $request['message']['key']['id'] === 'OLD');
    expect($stale->fresh()->media_path)->not->toBeNull();
});
