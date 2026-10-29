<?php

use App\Actions\Ai\ActivateAiProviderConnection;
use App\Actions\Ai\SaveAiProviderConnection;
use App\Actions\Ai\TestAiProviderConnection;
use App\Ai\Agents\ConnectionCheck;
use App\Enums\AiModel;
use App\Livewire\Admin\Ai\AiProviderList;
use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->admin = User::factory()->superAdmin()->create();
});

test('activating a connection deactivates the others', function () {
    $current = AiProviderConnection::factory()->active()->create(['created_by_id' => $this->admin->id]);
    $spare = AiProviderConnection::factory()->create(['created_by_id' => $this->admin->id]);
    $next = AiProviderConnection::factory()->create(['created_by_id' => $this->admin->id]);

    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->call('activate', $next->id)
        ->assertHasNoErrors()
        ->assertSee("data-ai-connection=\"{$next->friendly_id}\"\n                        data-ai-connection-active=\"true\"", false);

    expect(AiProviderConnection::query()->active()->pluck('id')->all())->toBe([$next->id])
        ->and($current->fresh()->is_active)->toBeFalse()
        ->and($spare->fresh()->is_active)->toBeFalse()
        ->and(AiProviderConnection::current()?->is($next))->toBeTrue();

    app(ActivateAiProviderConnection::class)($this->admin, $current);

    expect(AiProviderConnection::query()->active()->pluck('id')->all())->toBe([$current->id]);

    // The partial unique index refuses a second active row however it is written.
    expect(fn () => DB::table('ai_provider_connections')->where('id', $spare->id)->update(['is_active' => true]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('api key is never exposed after saving', function () {
    $apiKey = 'sk-live-very-secret-key-1234WXYZ';

    $component = Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->set('provider', AiProvider::ANTHROPIC)
        ->set('name', 'Claude')
        ->set('models', [AiModel::ClaudeHaiku55->value, AiModel::ClaudeSonnet55->value])
        ->set('apiKey', $apiKey)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '')
        ->assertSee('Connection Claude saved.')
        ->assertSee('••••WXYZ')
        ->assertDontSee($apiKey);

    $connection = AiProviderConnection::query()->sole();

    expect($connection->api_key)->toBe($apiKey)
        ->and($connection->api_key_last_four)->toBe('WXYZ')
        ->and($connection->is_active)->toBeFalse()
        ->and(DB::table('ai_provider_connections')->value('api_key'))->not->toContain($apiKey)
        ->and(json_encode($connection->toArray()))->not->toContain($apiKey)
        ->and(json_encode($component->snapshot))->not->toContain($apiKey);

    // Editing shows an empty key field and keeps the stored key when left empty.
    $component->call('edit', $connection->id)
        ->assertSet('apiKey', '')
        ->assertSet('name', 'Claude')
        ->assertSet('models', [AiModel::ClaudeHaiku55->value, AiModel::ClaudeSonnet55->value])
        ->assertDontSee($apiKey)
        ->set('model', 'claude-sonnet-5-5')
        ->call('save')
        ->assertHasNoErrors();

    expect($connection->fresh()->api_key)->toBe($apiKey)
        ->and($connection->fresh()->model)->toBe('claude-sonnet-5-5');

    $this->actingAs($this->admin)
        ->get(route('admin.ai-providers.index'))
        ->assertOk()
        ->assertSee('••••WXYZ')
        ->assertDontSee($apiKey);
});

test('a connection offers the ticked and the typed models, with a default among them', function () {
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->set('provider', AiProvider::OPENAI)
        ->assertSeeHtml('data-ai-model="'.AiModel::Gpt6Luna->value.'"')
        ->assertDontSeeHtml('data-ai-model="'.AiModel::ClaudeOpus55->value.'"')
        ->set('name', 'OpenAI')
        ->set('apiKey', 'sk-1234567890')
        ->set('models', [AiModel::Gpt6Luna->value, AiModel::Gpt6Astra->value])
        ->set('otherModels', " gpt-6-luna-preview,\ngpt-6-luna ")
        ->set('model', AiModel::Gpt6Astra->value)
        ->call('save')
        ->assertHasNoErrors();

    $connection = AiProviderConnection::query()->sole();

    expect($connection->models)->toBe(['gpt-6-luna', 'gpt-6-astra', 'gpt-6-luna-preview'])
        ->and($connection->model)->toBe('gpt-6-astra');

    // Changing the provider clears the models picked for the previous one.
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->call('edit', $connection->id)
        ->assertSet('models', ['gpt-6-luna', 'gpt-6-astra'])
        ->assertSet('otherModels', 'gpt-6-luna-preview')
        ->set('provider', AiProvider::ANTHROPIC)
        ->assertSet('models', [])
        ->assertSet('otherModels', '')
        ->assertSet('model', '');
});

test('saving validates the provider fields', function () {
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->set('provider', AiProvider::OPENAI_COMPATIBLE)
        ->set('name', 'Local')
        ->call('save')
        ->assertHasErrors(['api_key', 'base_url', 'models']);

    expect(fn () => app(SaveAiProviderConnection::class)($this->admin, [
        'provider' => 'skynet', 'name' => 'X', 'models' => ['y'], 'api_key' => 'abcd1234',
    ]))->toThrow(ValidationException::class);

    // The default must be one of the models the key offers.
    expect(fn () => app(SaveAiProviderConnection::class)($this->admin, [
        'provider' => AiProvider::OPENAI, 'name' => 'X', 'models' => ['gpt-6-luna'], 'model' => 'gpt-6-astra', 'api_key' => 'abcd1234',
    ]))->toThrow(ValidationException::class);

    expect(AiProviderConnection::query()->count())->toBe(0);
});

test('test connection reports provider failure', function () {
    $connection = AiProviderConnection::factory()->create([
        'created_by_id' => $this->admin->id,
        'api_key' => 'sk-wrong-key-ABCD',
        'api_key_last_four' => 'ABCD',
    ]);

    ConnectionCheck::fake(function () {
        throw new AiException('Incorrect API key provided: sk-wrong-key-ABCD.');
    });

    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->call('test', $connection->id)
        ->assertSet("testResults.{$connection->id}.successful", false)
        ->assertSee('data-ai-test-result="failure"', false)
        ->assertSee('Incorrect API key provided: ••••ABCD.')
        ->assertDontSee('sk-wrong-key-ABCD');

    ConnectionCheck::assertPrompted(ConnectionCheck::PROMPT);

    ConnectionCheck::fake(['OK']);

    expect(app(TestAiProviderConnection::class)($this->admin, $connection))
        ->toMatchArray(['successful' => true]);

    // The check is built from the connection's own credentials and model.
    expect((new ConnectionCheck($connection->load('provider')))->provider()[0]->name())->toStartWith('ondemand_')
        ->and($connection->providerConfig())->toMatchArray([
            'driver' => AiProvider::OPENAI,
            'key' => 'sk-wrong-key-ABCD',
            'models' => ['text' => ['default' => $connection->model]],
        ]);
});

test('only super admin manages ai providers', function () {
    $member = User::factory()->create();
    $connection = AiProviderConnection::factory()->create(['created_by_id' => $this->admin->id]);

    $this->actingAs($member)->get(route('admin.ai-providers.index'))->assertForbidden();

    Livewire::actingAs($member)->test(AiProviderList::class)->assertForbidden();

    expect(fn () => app(SaveAiProviderConnection::class)($member, [
        'provider' => AiProvider::OPENAI, 'name' => 'Mine', 'models' => ['gpt-6-luna'], 'api_key' => 'sk-1234567890',
    ]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ActivateAiProviderConnection::class)($member, $connection))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TestAiProviderConnection::class)($member, $connection))->toThrow(AuthorizationException::class)
        ->and($connection->fresh()->is_active)->toBeFalse()
        ->and(AiProviderConnection::query()->count())->toBe(1);
});
