<?php

use App\Actions\Ai\AssignAiPurpose;
use App\Ai\Agents\VoiceCommandAgent;
use App\Ai\Agents\WhatsappReplyAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Livewire\Admin\Ai\AiProviderList;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\AiPurposeAssignment;
use App\Models\User;
use App\Services\Ai\AiRoute;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->admin = User::factory()->superAdmin()->create();
    $this->inUse = AiProviderConnection::factory()->active()->create(['name' => 'Main', 'model' => 'gpt-5-mini']);
    $this->strong = AiProviderConnection::factory()->create([
        'name' => 'Strong', 'model' => 'claude-opus-5-5', 'models' => ['claude-opus-5-5', 'claude-sonnet-5-5'],
    ]);
});

test('each use goes through its own connection and model, the others through the one in use', function () {
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->assertSeeHtml('data-ai-purpose="whatsapp_reply"')
        ->assertSeeHtml('data-ai-purpose="voice_command"')
        ->set('assignments.whatsapp_reply.connection', $this->strong->friendly_id)
        ->set('assignments.whatsapp_reply.model', ' claude-sonnet-5-5 ')
        ->call('savePurpose', AiPurpose::WHATSAPP_REPLY)
        ->assertHasNoErrors();

    $reply = WhatsappReplyAgent::forUser($this->admin)->route;
    $voice = VoiceCommandAgent::forUser($this->admin)->route;

    expect($reply->connection->is($this->strong))->toBeTrue()
        ->and($reply->providerConfig()['models']['text']['default'])->toBe('claude-sonnet-5-5')
        ->and($voice->connection->is($this->inUse))->toBeTrue()
        ->and($voice->model)->toBe('gpt-5-mini');

    // Without a model of its own, the use takes the connection's.
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->assertSet('assignments.whatsapp_reply.connection', $this->strong->friendly_id)
        ->set('assignments.whatsapp_reply.model', '')
        ->call('savePurpose', AiPurpose::WHATSAPP_REPLY);

    expect(AiRoute::for(AiPurpose::WHATSAPP_REPLY)->model)->toBe('claude-opus-5-5');

    // Back on the connection in use.
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->set('assignments.whatsapp_reply.connection', '')
        ->call('savePurpose', AiPurpose::WHATSAPP_REPLY);

    expect(AiPurposeAssignment::query()->count())->toBe(0)
        ->and(AiRoute::for(AiPurpose::WHATSAPP_REPLY)->connection->is($this->inUse))->toBeTrue();
});

test('a use only picks a model its connection offers, and falls back to the default when it stops offering it', function () {
    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->set('assignments.voice_command.connection', $this->strong->friendly_id)
        ->assertSeeHtml('value="claude-sonnet-5-5"')
        ->set('assignments.voice_command.model', 'gpt-6-astra')
        ->call('savePurpose', AiPurpose::VOICE_COMMAND)
        ->assertHasErrors(['model']);

    expect(AiPurposeAssignment::query()->count())->toBe(0);

    app(AssignAiPurpose::class)($this->admin, AiPurpose::VOICE_COMMAND, $this->strong->friendly_id, 'claude-sonnet-5-5');

    expect(AiRoute::for(AiPurpose::VOICE_COMMAND)->model)->toBe('claude-sonnet-5-5');

    $this->strong->update(['models' => ['claude-opus-5-5']]);

    expect(AiRoute::for(AiPurpose::VOICE_COMMAND)->model)->toBe('claude-opus-5-5');
});

test('removing a picked connection puts the use back on the one in use', function () {
    app(AssignAiPurpose::class)($this->admin, AiPurpose::VOICE_COMMAND, $this->strong->friendly_id, null);

    $this->strong->delete();

    expect(AiRoute::for(AiPurpose::VOICE_COMMAND)->connection->is($this->inUse))->toBeTrue();
});

test('a use picked with no connection in use still works, and none at all is not configured', function () {
    app(AssignAiPurpose::class)($this->admin, AiPurpose::VOICE_COMMAND, $this->strong->friendly_id, null);
    $this->inUse->delete();

    expect(AiRoute::for(AiPurpose::VOICE_COMMAND)->connection->is($this->strong))->toBeTrue()
        ->and(AiRoute::for(AiPurpose::WHATSAPP_REPLY))->toBeNull()
        ->and(fn () => WhatsappReplyAgent::forUser($this->admin))->toThrow(AiProviderNotConfigured::class);
});

test('only the super admin picks the AI of a use', function () {
    $user = User::factory()->create();

    expect(fn () => app(AssignAiPurpose::class)($user, AiPurpose::VOICE_COMMAND, $this->strong->friendly_id, null))
        ->toThrow(AuthorizationException::class);
});
