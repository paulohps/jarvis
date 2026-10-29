<?php

use App\Actions\Voice\ExecuteVoiceAction;
use App\Jobs\DeliverWhatsappMessage;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceResult;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    Queue::fake();

    $this->user = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'America/Sao_Paulo']);
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->user)->create(['nickname' => 'Pessoal']);
    $this->ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana']);

    $this->ask = fn (string $body = 'Chego em 10 minutos'): VoiceResult => app(ExecuteVoiceAction::class)(
        $this->user,
        VoiceAction::from(VoiceAction::WHATSAPP_SEND, ['target' => 'ana', 'body' => $body]),
    );

    $this->confirm = fn (string $confirmationToken, ?string $bearer = null) => $this->withToken($bearer ?? $this->token)
        ->postJson('/api/v1/voice/commands/confirm', ['confirmation_token' => $confirmationToken]);
});

test('whatsapp voice command never sends without confirmation', function () {
    $result = ($this->ask)();

    expect($result->status)->toBe(VoiceResult::NEEDS_CONFIRMATION)
        ->and($result->message)->toBe('Send "Chego em 10 minutos" to Ana? Confirm to send.')
        ->and($result->confirmation['token'])->toBeString()->not->toBeEmpty()
        ->and($result->confirmation['expires_at'])->toBe('2026-10-05T12:05:00Z')
        ->and($result->confirmation['summary'])->toBe([
            'account' => 'Pessoal',
            'target' => 'Ana',
            'target_type' => 'contact',
            'body' => 'Chego em 10 minutos',
        ])
        ->and($result->toArray()['parameters']['target'])->toBe($this->ana->friendly_id);

    // Asking again only issues another token: still nothing sent.
    ($this->ask)();

    expect(WhatsappDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    // A wrong token sends nothing either.
    ($this->confirm)('not-a-token')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmation_token']);

    $this->withToken($this->token)
        ->postJson('/api/v1/voice/commands/confirm', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmation_token']);

    expect(WhatsappDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('confirmation token is single use and expires in five minutes', function () {
    $first = ($this->ask)('Primeira')->confirmation['token'];

    ($this->confirm)($first)->assertOk()->assertJsonPath('status', VoiceResult::DONE);

    // Reused: 422, nothing sent again.
    ($this->confirm)($first)
        ->assertUnprocessable()
        ->assertJsonPath('errors.confirmation_token.0', 'This confirmation expired or was already used. Say the command again.');

    expect(WhatsappDelivery::query()->count())->toBe(1);

    // Still valid just before five minutes…
    $second = ($this->ask)('Segunda')->confirmation['token'];
    $this->travel(4)->minutes();
    $this->travel(59)->seconds();

    ($this->confirm)($second)->assertOk();

    // …expired after.
    $third = ($this->ask)('Terceira')->confirmation['token'];
    $this->travel(5)->minutes();
    $this->travel(1)->seconds();

    ($this->confirm)($third)->assertUnprocessable()->assertJsonValidationErrors(['confirmation_token']);

    // Someone else's token is refused, and stays usable by its owner.
    $fourth = ($this->ask)('Quarta')->confirmation['token'];
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create()->createToken('Pixel')->plainTextToken;

    app('auth')->forgetGuards();

    ($this->confirm)($fourth, $intruder)->assertUnprocessable();

    app('auth')->forgetGuards();

    ($this->confirm)($fourth)->assertOk();

    expect(WhatsappDelivery::query()->orderBy('id')->pluck('body')->all())->toBe(['Primeira', 'Segunda', 'Quarta']);
});

test('confirmed voice send creates a voice delivery', function () {
    $token = ($this->ask)()->confirmation['token'];

    ($this->confirm)($token)
        ->assertOk()
        ->assertExactJson([
            'action' => VoiceAction::WHATSAPP_SEND,
            'status' => VoiceResult::DONE,
            'message' => 'Sending "Chego em 10 minutos" to Ana.',
            'parameters' => [
                'target' => $this->ana->friendly_id,
                'body' => 'Chego em 10 minutos',
                'account' => $this->account->friendly_id,
            ],
            'clarification' => null,
            'confirmation' => null,
        ]);

    $delivery = WhatsappDelivery::query()->sole();

    expect($delivery->source->slug)->toBe(WhatsappDeliverySource::VOICE)
        ->and($delivery->status->slug)->toBe(DeliveryStatus::PENDING)
        ->and($delivery->whatsapp_target_id)->toBe($this->ana->id)
        ->and($delivery->whatsapp_message_id)->toBeNull()
        ->and($delivery->triggered_by_id)->toBe($this->user->id)
        ->and($delivery->body)->toBe('Chego em 10 minutos');

    Queue::assertPushed(DeliverWhatsappMessage::class, 1);

    // It shows in the history like any other delivery.
    $this->withToken($this->token)
        ->getJson('/api/v1/whatsapp/deliveries')
        ->assertOk()
        ->assertJsonPath('data.0.source', 'voice');

    // An account disconnected in the meantime: a spoken failure, nothing queued.
    $this->account->update(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::CLOSED)]);
    $late = ($this->ask)('Atrasado')->confirmation['token'];

    ($this->confirm)($late)->assertOk()->assertJsonPath('status', VoiceResult::FAILED);

    expect(WhatsappDelivery::query()->count())->toBe(1);
});
