<?php

use App\Actions\Ai\DeleteAiProviderConnection;
use App\Livewire\Admin\Ai\AiProviderList;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\AiPurposeAssignment;
use App\Models\AiUsage;
use App\Models\User;
use App\Services\Ai\AiRoute;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->admin = User::factory()->superAdmin()->create();
    $this->current = AiProviderConnection::factory()->active()->create(['created_by_id' => $this->admin->id, 'name' => 'Main']);
    $this->spare = AiProviderConnection::factory()->create(['created_by_id' => $this->admin->id, 'name' => 'Spare']);
});

test('a removed connection is soft deleted, its uses go back to the one in use and its usage stays', function () {
    AiPurposeAssignment::query()->create(['ai_purpose_id' => AiPurpose::idFor(AiPurpose::VOICE_COMMAND), 'ai_provider_connection_id' => $this->spare->id]);
    $usage = AiUsage::factory()->create(['ai_provider_connection_id' => $this->spare->id]);

    expect(AiRoute::for(AiPurpose::VOICE_COMMAND)?->connection->is($this->spare))->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(AiProviderList::class)
        ->assertSeeHtml("data-ai-connection-delete=\"{$this->spare->friendly_id}\"")
        ->call('edit', $this->spare->id)
        ->call('delete', $this->spare->id)
        ->assertHasNoErrors()
        ->assertSet('editingId', null)
        ->assertSet('assignments.'.AiPurpose::VOICE_COMMAND.'.connection', '')
        ->assertDontSeeHtml("data-ai-connection-delete=\"{$this->spare->friendly_id}\"");

    $trashed = AiProviderConnection::withTrashed()->find($this->spare->id);

    expect(AiProviderConnection::query()->find($this->spare->id))->toBeNull()
        ->and($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->api_key)->toBeNull()
        ->and(AiPurposeAssignment::query()->count())->toBe(0)
        ->and(AiRoute::for(AiPurpose::VOICE_COMMAND)?->connection->is($this->current))->toBeTrue()
        ->and($usage->fresh()->ai_provider_connection_id)->toBe($this->spare->id)
        ->and($usage->fresh()->connection?->name)->toBe('Spare');
});

test('removing the connection in use leaves none in use, and another can be put in use', function () {
    app(DeleteAiProviderConnection::class)($this->admin, $this->current);

    expect(AiProviderConnection::current())->toBeNull()
        ->and(AiRoute::for(AiPurpose::VOICE_COMMAND))->toBeNull()
        ->and(AiProviderConnection::withTrashed()->find($this->current->id)->is_active)->toBeFalse();

    Livewire::actingAs($this->admin)->test(AiProviderList::class)->call('activate', $this->spare->id)->assertHasNoErrors();

    expect(AiProviderConnection::current()?->is($this->spare))->toBeTrue();
});

test('only a super admin removes a connection', function () {
    expect(fn () => app(DeleteAiProviderConnection::class)(User::factory()->create(), $this->spare))
        ->toThrow(AuthorizationException::class);

    expect($this->spare->fresh()->deleted_at)->toBeNull();
});
