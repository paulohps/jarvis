<?php

use App\Actions\Voice\ExecuteVoiceAction;
use App\Ai\Agents\VoiceCommandAgent;
use App\Ai\Tools\VoiceActionTool;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\AiProviderConnection;
use App\Models\Locale;
use App\Models\Module;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceResult;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()
        ->withModules([Module::SHOPPING, Module::REMINDERS])
        ->create([
            'timezone' => 'America/Sao_Paulo',
            'locale_id' => Locale::idFor(Locale::PT_BR),
        ]);
});

test('agent maps add item utterance to shopping add item', function () {
    AiProviderConnection::factory()->active()->create(['model' => 'gpt-5-mini']);

    VoiceCommandAgent::fake([
        new ToolCall('call_1', 'shopping_add_item', ['item' => 'leite', 'list' => 'dessa semana']),
        'Pronto, adicionei leite.',
    ])->preventStrayPrompts();

    $agent = VoiceCommandAgent::forUser($this->user);
    $interpretation = $agent->interpret('adicione leite na lista de compras dessa semana');

    expect($interpretation->action?->name)->toBe(VoiceAction::SHOPPING_ADD_ITEM)
        ->and($interpretation->action?->parameters)->toBe(['item' => 'leite', 'quantity' => null, 'list' => 'dessa semana'])
        ->and($interpretation->reply)->toBe('Pronto, adicionei leite.');

    VoiceCommandAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_starts_with($prompt->prompt, "adicione leite na lista de compras dessa semana\n\n(Now is "));

    // One tool per voice action, named without the dot.
    expect(array_map(fn (VoiceActionTool $tool): string => $tool->name(), [...$agent->tools()]))->toBe([
        'shopping_add_item',
        'shopping_check_item',
        'reminders_create',
        'reminders_complete',
        'whatsapp_send',
    ]);

    // The instructions answer in the user's language.
    VoiceCommandAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        (string) $prompt->agent->instructions(),
        '(pt_BR)',
    ));
});

test('agent records nothing for an utterance it cannot map', function () {
    AiProviderConnection::factory()->active()->create();

    // Invalid arguments go back to the model, which then gives up.
    VoiceCommandAgent::fake([
        new ToolCall('call_1', 'shopping_add_item', ['item' => '']),
        'Só consigo cuidar de listas, lembretes e WhatsApp.',
    ]);

    $interpretation = VoiceCommandAgent::forUser($this->user)->interpret('qual a previsão do tempo?');

    expect($interpretation->action)->toBeNull()
        ->and($interpretation->reply)->toBe('Só consigo cuidar de listas, lembretes e WhatsApp.');
});

test('missing ai provider returns friendly error', function () {
    // A connection that is not active does not count.
    AiProviderConnection::factory()->create();

    VoiceCommandAgent::fake();

    expect(fn () => VoiceCommandAgent::forUser($this->user))
        ->toThrow(AiProviderNotConfigured::class, 'AI provider not configured.');

    try {
        app()->setLocale('pt_BR');
        VoiceCommandAgent::forUser($this->user);
        $this->fail('An interpreter was built without a connection.');
    } catch (AiProviderNotConfigured $exception) {
        expect($exception->getStatusCode())->toBe(503)
            ->and($exception->getMessage())->toBe('Provedor de IA não configurado.');
    }

    VoiceCommandAgent::assertNeverPrompted();
});

test('relative dates use the user timezone', function () {
    AiProviderConnection::factory()->active()->create();

    // 02:30 in UTC is still Sunday 23:30 in São Paulo: "amanhã" is Monday the 5th.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 02:30', 'UTC'));

    VoiceCommandAgent::fake([
        new ToolCall('call_1', 'reminders_create', ['title' => 'Levar o carro', 'due_at' => '2026-10-05T09:00']),
        'Lembrete criado.',
    ]);

    $interpretation = VoiceCommandAgent::forUser($this->user)->interpret('criar lembrete levar o carro amanhã às 9h');

    VoiceCommandAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        $prompt->prompt,
        "Now is Sunday, 2026-10-04 23:30 in the user's timezone (America/Sao_Paulo).",
    ));

    $result = app(ExecuteVoiceAction::class)($this->user, $interpretation->action);

    // 09:00 in São Paulo is 12:00 UTC.
    expect($result->status)->toBe(VoiceResult::DONE)
        ->and(Reminder::query()->sole()->occurrences()->sole()->due_at->toIso8601ZuluString())->toBe('2026-10-05T12:00:00Z');

    // Someone in Tokyo at the same instant is already on Monday afternoon.
    $tokyo = User::factory()->withModules([Module::REMINDERS])->create(['timezone' => 'Asia/Tokyo']);

    expect(VoiceCommandAgent::forUser($tokyo)->promptFor('criar lembrete'))
        ->toContain("Now is Monday, 2026-10-05 11:30 in the user's timezone (Asia/Tokyo).");
});
