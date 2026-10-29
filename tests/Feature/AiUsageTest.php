<?php

use App\Actions\Ai\SaveAiModelPrice;
use App\Ai\Agents\VoiceCommandAgent;
use App\Ai\Agents\WhatsappReplyAgent;
use App\Livewire\Admin\Ai\AiUsageReport;
use App\Models\AiModelPrice;
use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\AiUsage;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'UTC'));

    $this->admin = User::factory()->superAdmin()->create(['timezone' => 'UTC']);
});

/**
 * An Anthropic Messages API answer, with the cache buckets it reports apart.
 *
 * @return array<string, mixed>
 */
function anthropicAnswer(string $text, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): array
{
    return [
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cache_read_input_tokens' => $cacheRead,
            'cache_creation_input_tokens' => $cacheWrite,
        ],
    ];
}

test('a whatsapp draft caches the instructions, keeps the time out of them and counts its tokens', function () {
    $owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'America/Sao_Paulo']);
    $account = WhatsappAccount::factory()->open()->for($owner)->create();
    $ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $account->id, 'name' => 'Ana']);
    $bia = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $account->id, 'name' => 'Bia']);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $ana->id, 'from_me' => true, 'body' => 'Segredo da conversa']);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $bia->id, 'from_me' => true, 'body' => 'bora sim kkk']);

    $connection = AiProviderConnection::factory()->active()->create([
        'ai_provider_id' => AiProvider::idFor(AiProvider::ANTHROPIC),
        'model' => 'claude-sonnet-5-5',
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicAnswer('Oi Ana!', 40, 12, cacheRead: 1500))]);

    expect(WhatsappReplyAgent::forUser($owner)->compose($ana, 'diz oi'))->toBe('Oi Ana!');

    Http::assertSent(function (Request $request): bool {
        $system = $request['system'];
        $prompt = $request['messages'][0]['content'];
        $prompt = is_array($prompt) ? $prompt[0]['text'] : $prompt;

        // The other chats' samples are the cached instructions; this chat and the time are not in them.
        return $system[0]['cache_control'] === ['type' => 'ephemeral']
            && str_contains($system[0]['text'], 'bora sim kkk')
            && ! str_contains($system[0]['text'], 'Segredo da conversa')
            && ! str_contains($system[0]['text'], 'Now is Thursday')
            && str_contains($prompt, 'Now is Thursday, 2026-10-08 12:00 (America/Sao_Paulo).');
    });

    $usage = AiUsage::query()->sole();

    expect($usage->ai_provider_connection_id)->toBe($connection->id)
        ->and($usage->ai_provider_id)->toBe(AiProvider::idFor(AiProvider::ANTHROPIC))
        ->and($usage->ai_purpose_id)->toBe(AiPurpose::idFor(AiPurpose::WHATSAPP_REPLY))
        ->and($usage->user_id)->toBe($owner->id)
        ->and($usage->model)->toBe('claude-sonnet-5-5')
        ->and($usage->input_tokens)->toBe(1540)
        ->and($usage->cache_read_tokens)->toBe(1500)
        ->and($usage->output_tokens)->toBe(12)
        ->and($usage->succeeded)->toBeTrue();
});

test('a voice command caches the tools and the instructions, and a failed call is counted with its error', function () {
    AiProviderConnection::factory()->active()->create([
        'ai_provider_id' => AiProvider::idFor(AiProvider::ANTHROPIC),
        'model' => 'claude-haiku-5-5',
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);

    expect(fn () => VoiceCommandAgent::forUser($this->admin)->interpret('adicione leite'))->toThrow(ProviderOverloadedException::class);

    Http::assertSent(fn (Request $request): bool => $request['system'][0]['cache_control'] === ['type' => 'ephemeral']
        && $request['tools'][array_key_last($request['tools'])]['cache_control'] === ['type' => 'ephemeral']
        && ! str_contains($request['system'][0]['text'], 'Now is Thursday'));

    $usage = AiUsage::query()->sole();

    expect($usage->succeeded)->toBeFalse()
        ->and($usage->input_tokens)->toBe(0)
        ->and($usage->ai_purpose_id)->toBe(AiPurpose::idFor(AiPurpose::VOICE_COMMAND))
        ->and($usage->error)->not->toBeNull();
});

test('the report sums the period, estimates the spend from the prices and says when it is partial', function () {
    $priced = ['ai_provider_id' => AiProvider::idFor(AiProvider::OPENAI), 'model' => 'gpt-6-luna'];
    $member = User::factory()->create(['name' => 'Carla']);

    AiModelPrice::query()->create([...$priced, 'input_per_million' => 2, 'output_per_million' => 8, 'cache_read_per_million' => 0.2]);

    // 900k uncached input ($1.80) + 100k from cache ($0.02) + 50k output ($0.40) = $2.22.
    AiUsage::factory()->create([...$priced, 'input_tokens' => 1_000_000, 'cache_read_tokens' => 100_000, 'output_tokens' => 50_000,
        'ai_purpose_id' => AiPurpose::idFor(AiPurpose::WHATSAPP_REPLY), 'user_id' => $member->id]);
    AiUsage::factory()->failed()->create([...$priced, 'ai_purpose_id' => AiPurpose::idFor(AiPurpose::VOICE_COMMAND)]);
    // Out of the last 7 days.
    AiUsage::factory()->create([...$priced, 'input_tokens' => 5_000_000, 'created_at' => now()->subDays(10)]);

    $report = Livewire::actingAs($this->admin)
        ->test(AiUsageReport::class)
        ->set('period', '7d');

    expect($report->instance()->summary())->toMatchArray([
        'calls' => 2,
        'failed' => 1,
        'input' => 1_000_000,
        'cache_read' => 100_000,
        'cache_share' => 0.1,
        'cost_complete' => true,
    ])->and(round($report->instance()->summary()['cost'], 4))->toBe(2.22);

    $report->assertSeeHtml('data-ai-usage-row="Carla"')
        ->assertSeeHtml('data-ai-usage-row="WhatsApp replies"')
        ->assertSee('US$ 2.22');

    // A model without a price leaves the spend partial.
    AiUsage::factory()->create(['model' => 'gpt-6-astra', 'input_tokens' => 1000]);

    $report = Livewire::actingAs($this->admin)->test(AiUsageReport::class, ['period' => '7d']);

    expect($report->instance()->summary()['cost_complete'])->toBeFalse();
    $report->assertSee('≥ US$ 2.22');
});

test('the super admin types and removes the price of a model', function () {
    AiProviderConnection::factory()->create(['model' => 'gpt-6-luna']);

    Livewire::actingAs($this->admin)
        ->test(AiUsageReport::class)
        ->assertSeeHtml('data-ai-price="openai|gpt-6-luna"')
        ->call('editPrice', 'openai|gpt-6-luna')
        ->set('price.input', '-1')
        ->set('price.output', '8')
        ->call('savePrice')
        ->assertHasErrors(['input'])
        ->set('price.input', '1.25')
        ->set('price.cache_read', '0.125')
        ->call('savePrice')
        ->assertHasNoErrors()
        ->assertSet('editingPrice', null);

    $price = AiModelPrice::query()->sole();

    expect($price->model)->toBe('gpt-6-luna')
        ->and($price->input_per_million)->toBe('1.2500')
        ->and($price->cache_read_per_million)->toBe('0.1250')
        ->and($price->cache_write_per_million)->toBeNull();

    app(SaveAiModelPrice::class)($this->admin, AiProvider::OPENAI, 'gpt-6-luna', ['input' => '', 'output' => '']);

    expect(AiModelPrice::query()->count())->toBe(0)
        ->and(AiModelPrice::onlyTrashed()->count())->toBe(1);

    expect(fn () => app(SaveAiModelPrice::class)($this->admin, AiProvider::OPENAI, 'gpt-6-luna', ['input' => '1', 'output' => '']))
        ->toThrow(ValidationException::class);
});

test('a removed price keeps the cost of what was used before, and typed again it comes back', function () {
    $priced = ['ai_provider_id' => AiProvider::idFor(AiProvider::OPENAI), 'model' => 'gpt-6-luna'];
    AiProviderConnection::factory()->create(['model' => 'gpt-6-luna']);
    AiModelPrice::query()->create([...$priced, 'input_per_million' => 2, 'output_per_million' => 8]);

    // 1M input at $2 = $2.00, used yesterday.
    AiUsage::factory()->create([...$priced, 'input_tokens' => 1_000_000, 'output_tokens' => 0, 'created_at' => now()->subDay()]);

    Livewire::actingAs($this->admin)
        ->test(AiUsageReport::class)
        ->assertSeeHtml('data-ai-price-remove="openai|gpt-6-luna"')
        ->call('removePrice', 'openai|gpt-6-luna')
        ->assertDontSeeHtml('data-ai-price-remove="openai|gpt-6-luna"');

    $price = AiModelPrice::withTrashed()->sole();

    expect(AiModelPrice::query()->count())->toBe(0)
        ->and($price->deleted_at)->not->toBeNull();

    // Used after the removal: no price for it, so the spend is partial.
    $this->travel(1)->minutes();
    AiUsage::factory()->create([...$priced, 'input_tokens' => 500_000, 'output_tokens' => 0]);

    $summary = Livewire::actingAs($this->admin)->test(AiUsageReport::class, ['period' => '7d'])->instance()->summary();

    expect(round($summary['cost'], 4))->toBe(2.0)
        ->and($summary['cost_complete'])->toBeFalse();

    // Typed again, the same row comes back and prices everything.
    app(SaveAiModelPrice::class)($this->admin, AiProvider::OPENAI, 'gpt-6-luna', ['input' => '4', 'output' => '8']);

    $summary = Livewire::actingAs($this->admin)->test(AiUsageReport::class, ['period' => '7d'])->instance()->summary();

    expect(AiModelPrice::query()->sole()->id)->toBe($price->id)
        ->and(round($summary['cost'], 4))->toBe(6.0)
        ->and($summary['cost_complete'])->toBeTrue();
});

test('only the super admin sees the usage', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('admin.ai-usage.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.ai-usage.index'))->assertOk()->assertSee(__('No AI call in this period'));
});
