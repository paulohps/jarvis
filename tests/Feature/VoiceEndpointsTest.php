<?php

use App\Ai\Agents\VoiceCommandAgent;
use App\Models\AiProviderConnection;
use App\Models\Module;
use App\Models\Reminder;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceResult;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    Queue::fake();

    $this->user = User::factory()
        ->withModules([Module::SHOPPING, Module::REMINDERS, Module::WHATSAPP])
        ->create(['timezone' => 'America/Sao_Paulo']);
    $this->token = $this->user->createToken('iPhone')->plainTextToken;

    AiProviderConnection::factory()->active()->create();

    $this->week = ShoppingList::factory()->create(['name' => 'Compras da semana']);
    $this->month = ShoppingList::factory()->create(['name' => 'Compras do mês']);

    $this->remote = fn (string $text) => $this->withToken($this->token)->postJson('/api/v1/voice/commands', ['text' => $text]);
    $this->structured = fn (string $action, array $parameters) => $this->withToken($this->token)
        ->postJson('/api/v1/voice/actions', ['action' => $action, 'parameters' => $parameters]);
});

test('remote and structured endpoints produce the same effect', function () {
    VoiceCommandAgent::fake([
        new ToolCall('call_1', 'shopping_add_item', ['item' => 'leite', 'quantity' => '2 L', 'list' => 'dessa semana']),
        'Pronto.',
        new ToolCall('call_2', 'reminders_create', ['title' => 'Pagar a escola', 'due_at' => '2026-10-06T09:00']),
        'Pronto.',
    ])->preventStrayPrompts();

    $remote = ($this->remote)('adicione 2 litros de leite na lista de compras dessa semana')->assertOk()->json();
    $structured = ($this->structured)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'leite', 'quantity' => '2 L', 'list' => 'dessa semana'])->assertOk()->json();

    // The same answer, and the same item added twice to the same list.
    expect($remote)->toBe($structured)
        ->and($remote['status'])->toBe(VoiceResult::DONE)
        ->and($remote['message'])->toBe('Added "leite" to "Compras da semana".')
        ->and($this->week->items()->get(['name', 'quantity_note', 'created_by_id'])->toArray())->toBe([
            ['name' => 'leite', 'quantity_note' => '2 L', 'created_by_id' => $this->user->id],
            ['name' => 'leite', 'quantity_note' => '2 L', 'created_by_id' => $this->user->id],
        ])
        ->and($this->month->items()->count())->toBe(0);

    // A reminder: the same schedule from either interpreter.
    $remoteReminder = ($this->remote)('criar lembrete pagar a escola amanhã às 9h')->assertOk()->json();
    $structuredReminder = ($this->structured)(VoiceAction::REMINDERS_CREATE, ['title' => 'Pagar a escola', 'due_at' => '2026-10-06T09:00'])->assertOk()->json();

    expect($remoteReminder)->toBe($structuredReminder)
        ->and(Reminder::query()->with('occurrences')->get()->map(fn (Reminder $reminder): array => [
            $reminder->title,
            $reminder->occurrences->sole()->due_at->toIso8601ZuluString(),
        ])->all())->toBe([
            ['Pagar a escola', '2026-10-06T12:00:00Z'],
            ['Pagar a escola', '2026-10-06T12:00:00Z'],
        ]);
});

test('voice endpoint response matches documented shape', function () {
    $documented = Yaml::parseFile(base_path('docs/api/v1.yaml'))['components']['schemas']['VoiceCommandResult'];

    $account = WhatsappAccount::factory()->open()->for($this->user)->create(['nickname' => 'Pessoal']);
    WhatsappTarget::factory()->create(['whatsapp_account_id' => $account->id, 'name' => 'Ana']);

    VoiceCommandAgent::fake([
        new ToolCall('call_1', 'shopping_add_item', ['item' => 'leite', 'list' => 'dessa semana']),
        'Pronto.',
        'Só cuido de listas, lembretes e WhatsApp.',
    ]);

    $responses = [
        VoiceResult::DONE => ($this->remote)('adicione leite na lista de compras dessa semana'),
        VoiceResult::FAILED => ($this->remote)('qual a previsão do tempo?'),
        VoiceResult::NEEDS_CLARIFICATION => ($this->structured)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'pão', 'list' => 'compras']),
        VoiceResult::NEEDS_CONFIRMATION => ($this->structured)(VoiceAction::WHATSAPP_SEND, ['target' => 'Ana', 'body' => 'Oi']),
    ];

    foreach ($responses as $status => $response) {
        $body = $response->assertOk()->json();

        expect(array_keys($body))->toBe($documented['required'])
            ->and($body['status'])->toBe($status)->toBeIn($documented['properties']['status']['enum'])
            ->and($body['action'])->toBeIn($documented['properties']['action']['enum'])
            ->and($body['message'])->toBeString()->not->toBeEmpty();
    }

    $responses[VoiceResult::DONE]->assertJsonPath('action', 'shopping.add_item')->assertJsonPath('parameters.list', 'dessa semana');
    $responses[VoiceResult::FAILED]->assertJsonPath('action', null)->assertJsonPath('message', 'Só cuido de listas, lembretes e WhatsApp.');

    $responses[VoiceResult::NEEDS_CLARIFICATION]
        ->assertJsonPath('clarification.parameter', 'list')
        ->assertJsonPath('clarification.options.*.id', [$this->week->friendly_id, $this->month->friendly_id])
        ->assertJsonStructure(['clarification' => ['parameter', 'options' => [['id', 'label']]]]);

    $responses[VoiceResult::NEEDS_CONFIRMATION]
        ->assertJsonStructure(['confirmation' => ['token', 'expires_at', 'summary' => ['account', 'target', 'target_type', 'body']]])
        ->assertJsonPath('confirmation.summary.target', 'Ana');

    // Malformed input is a 422; a disabled module is a `forbidden` answer.
    ($this->structured)('shopping.empty_the_fridge', [])->assertUnprocessable()->assertJsonValidationErrors(['action']);
    ($this->structured)(VoiceAction::SHOPPING_ADD_ITEM, [])->assertUnprocessable()->assertJsonValidationErrors(['parameters.item']);
    $this->withToken($this->token)->postJson('/api/v1/voice/commands', ['text' => ''])->assertUnprocessable()->assertJsonValidationErrors(['text']);

    app('auth')->forgetGuards();
    $shopper = User::factory()->withModules([Module::SHOPPING])->create()->createToken('Pixel')->plainTextToken;

    $this->withToken($shopper)
        ->postJson('/api/v1/voice/actions', ['action' => VoiceAction::REMINDERS_CREATE, 'parameters' => ['title' => 'X']])
        ->assertOk()
        ->assertJsonPath('status', VoiceResult::FORBIDDEN)
        ->assertJsonPath('message', 'The Agenda module is switched off for you.');

    // No active AI provider: a friendly error, in the user's language.
    AiProviderConnection::query()->update(['is_active' => false]);
    app('auth')->forgetGuards();

    $this->withToken($this->token)
        ->postJson('/api/v1/voice/commands', ['text' => 'adicione leite'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'AI provider not configured.');

    $this->withToken($this->token)
        ->withHeader('Accept-Language', 'pt-BR')
        ->postJson('/api/v1/voice/actions', ['action' => VoiceAction::SHOPPING_ADD_ITEM, 'parameters' => ['item' => 'café', 'list' => $this->week->friendly_id]])
        ->assertOk()
        ->assertJsonPath('status', VoiceResult::DONE);

    // Unauthenticated: 401.
    app('auth')->forgetGuards();
    $this->withoutToken()->postJson('/api/v1/voice/commands', ['text' => 'oi'])->assertUnauthorized();
});

test('voice endpoints are rate limited', function () {
    VoiceCommandAgent::fake(fn () => 'Não entendi.');

    for ($attempt = 1; $attempt <= 19; $attempt++) {
        ($this->structured)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => "item {$attempt}", 'list' => $this->week->friendly_id])->assertOk();
    }

    // The two endpoints share the 20 per minute.
    ($this->remote)('oi')->assertOk();
    ($this->remote)('oi')->assertTooManyRequests();
    ($this->structured)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'demais', 'list' => $this->week->friendly_id])->assertTooManyRequests();

    expect($this->week->items()->count())->toBe(19);

    // Per user: someone else still gets through.
    app('auth')->forgetGuards();
    $other = User::factory()->withModules([Module::SHOPPING])->create()->createToken('Pixel')->plainTextToken;

    $this->withToken($other)
        ->postJson('/api/v1/voice/actions', ['action' => VoiceAction::SHOPPING_ADD_ITEM, 'parameters' => ['item' => 'pão', 'list' => $this->week->friendly_id]])
        ->assertOk();

    // A minute later, again.
    $this->travel(61)->seconds();
    app('auth')->forgetGuards();

    ($this->remote)('oi')->assertOk();
});
