<?php

namespace App\Livewire\Admin\Ai;

use App\Actions\Ai\ActivateAiProviderConnection;
use App\Actions\Ai\AssignAiPurpose;
use App\Actions\Ai\DeleteAiProviderConnection;
use App\Actions\Ai\SaveAiProviderConnection;
use App\Actions\Ai\TestAiProviderConnection;
use App\Enums\AiModel;
use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The remote AI provider of the admin area (US-2.3): the form that adds or
 * edits a connection — the provider, the key and the models the key offers
 * (the provider's AiModel cases, plus any other id) with the default among
 * them — and the stored connections, with the one in use marked. Each
 * connection can be put in use, tested and removed (a soft delete: the
 * uses that had it go back to the connection in use). Below, each use of the AI (voice
 * commands, WhatsApp replies…) can get its own connection and one of the
 * models it offers (AssignAiPurpose); the ones left on "the connection in
 * use" follow it.
 *
 * The key is write-only: the field always starts empty (empty keeps the
 * stored key when editing) and the list shows only its last four
 * characters.
 *
 * @property-read Collection<int, AiProviderConnection> $connections
 * @property-read Collection<int, AiProvider> $providers
 * @property-read Collection<int, AiPurpose> $purposes
 */
#[Layout('layouts::app-shell')]
class AiProviderList extends Component
{
    public ?int $editingId = null;

    public string $provider = AiProvider::OPENAI;

    public string $name = '';

    /**
     * @var list<string> the provider's AiModel values ticked
     */
    public array $models = [];

    /**
     * Other model ids, one per line or separated by commas.
     */
    public string $otherModels = '';

    /**
     * The default model, one of the picked ones ('' for the first).
     */
    public string $model = '';

    public string $apiKey = '';

    public string $baseUrl = '';

    public ?string $status = null;

    /**
     * @var array<int|string, array{successful: bool, message: string}> connection id => outcome of the last test
     */
    public array $testResults = [];

    /**
     * @var array<string, array{connection: string, model: string}> purpose slug => picked connection (friendly id, '' for the one in use) and model ('' for the connection's own)
     */
    public array $assignments = [];

    public function mount(): void
    {
        Gate::forUser($this->actor())->authorize('super-admin');

        $this->loadAssignments();
    }

    /**
     * @return Collection<int, AiPurpose>
     */
    #[Computed]
    public function purposes(): Collection
    {
        return AiPurpose::query()->with('assignment.connection')->orderBy('name')->get();
    }

    public function savePurpose(string $purpose, AssignAiPurpose $assignPurpose): void
    {
        $picked = $this->assignments[$purpose] ?? ['connection' => '', 'model' => ''];

        $assignPurpose($this->actor(), $purpose, $picked['connection'] ?: null, $picked['model'] ?: null);

        $this->status = __('The AI for :purpose was saved.', ['purpose' => __(AiPurpose::bySlug($purpose)->name)]);
        unset($this->purposes);
        $this->loadAssignments();
    }

    /**
     * @return Collection<int, AiProviderConnection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return AiProviderConnection::query()
            ->with('provider')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, AiProvider>
     */
    #[Computed]
    public function providers(): Collection
    {
        return AiProvider::query()->active()->orderBy('name')->get();
    }

    /**
     * The main models of the provider picked in the form.
     *
     * @return list<AiModel>
     */
    public function providerModels(): array
    {
        return AiModel::forProvider($this->provider);
    }

    /**
     * The models the form offers: the ticked ones, then the other ids.
     *
     * @return list<string>
     */
    public function pickedModels(): array
    {
        $others = preg_split('/[\s,]+/', $this->otherModels, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique([...$this->models, ...$others]));
    }

    /**
     * The models a stored connection offers (by friendly id), for the uses.
     *
     * @return list<string>
     */
    public function connectionModels(string $connectionId): array
    {
        return $this->connections->firstWhere('friendly_id', $connectionId)->models ?? [];
    }

    public function updatedProvider(): void
    {
        $this->reset('models', 'otherModels', 'model');
    }

    public function updatedModels(): void
    {
        $this->keepDefaultModelPicked();
    }

    public function updatedOtherModels(): void
    {
        $this->keepDefaultModelPicked();
    }

    /**
     * A use that changes connection goes back to the connection's default model.
     */
    public function updatedAssignments(mixed $value, string $key): void
    {
        if (str_ends_with($key, '.connection')) {
            $this->assignments[str($key)->beforeLast('.')->value()]['model'] = '';
        }
    }

    public function requiresBaseUrl(): bool
    {
        return (bool) $this->providers->firstWhere('slug', $this->provider)?->requires_base_url;
    }

    public function edit(int $connectionId): void
    {
        $connection = AiProviderConnection::query()->with('provider')->findOrFail($connectionId);

        $this->editingId = $connection->id;
        $this->provider = $connection->provider->slug;
        $this->name = $connection->name;
        $mainModels = array_map(fn (AiModel $model): string => $model->value, AiModel::forProvider($this->provider));
        $this->models = array_values(array_intersect($connection->models, $mainModels));
        $this->otherModels = implode("\n", array_diff($connection->models, $mainModels));
        $this->model = $connection->model;
        $this->apiKey = '';
        $this->baseUrl = (string) $connection->base_url;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->resetForm();
    }

    public function save(SaveAiProviderConnection $saveConnection): void
    {
        $editing = $this->editingId === null ? null : AiProviderConnection::query()->findOrFail($this->editingId);

        $connection = $saveConnection($this->actor(), [
            'provider' => $this->provider,
            'name' => $this->name,
            'models' => $this->pickedModels(),
            'model' => $this->model,
            'api_key' => $this->apiKey,
            'base_url' => $this->baseUrl,
        ], $editing);

        $this->resetForm();
        $this->status = __('Connection :name saved.', ['name' => $connection->name]);
        unset($this->testResults[$connection->id], $this->connections);
    }

    public function activate(int $connectionId, ActivateAiProviderConnection $activateConnection): void
    {
        $connection = $activateConnection($this->actor(), AiProviderConnection::query()->findOrFail($connectionId));

        $this->status = __('Connection :name is now in use.', ['name' => $connection->name]);
        unset($this->connections, $this->purposes);
    }

    public function delete(int $connectionId, DeleteAiProviderConnection $deleteConnection): void
    {
        $connection = $deleteConnection($this->actor(), AiProviderConnection::query()->findOrFail($connectionId));

        if ($this->editingId === $connection->id) {
            $this->resetForm();
        }

        $this->status = __('Connection :name removed.', ['name' => $connection->name]);
        unset($this->testResults[$connection->id], $this->connections, $this->purposes);
        $this->loadAssignments();
    }

    public function test(int $connectionId, TestAiProviderConnection $testConnection): void
    {
        $this->testResults[$connectionId] = $testConnection($this->actor(), AiProviderConnection::query()->findOrFail($connectionId));
    }

    public function render(): View
    {
        return view('livewire.admin.ai.ai-provider-list')->title(__('AI provider'));
    }

    private function loadAssignments(): void
    {
        $this->assignments = $this->purposes->mapWithKeys(fn (AiPurpose $purpose): array => [$purpose->slug => [
            'connection' => (string) $purpose->assignment?->connection->friendly_id,
            'model' => (string) $purpose->assignment?->model,
        ]])->all();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'provider', 'name', 'models', 'otherModels', 'model', 'apiKey', 'baseUrl');
        $this->resetValidation();
    }

    private function keepDefaultModelPicked(): void
    {
        if (! in_array($this->model, $this->pickedModels(), true)) {
            $this->model = '';
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
