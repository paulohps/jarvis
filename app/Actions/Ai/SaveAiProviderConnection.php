<?php

namespace App\Actions\Ai;

use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The super admin stores the credentials of a remote AI provider (US-2.3):
 * one of the `laravel/ai` drivers in `ai_providers`, a name, the API key,
 * plus the base URL for the providers that need one, and the models the key
 * offers (the provider's AiModel cases or any other id) with the default
 * among them (the first one when none is given).
 *
 * The key is encrypted at rest (cast) and only its last four characters are
 * kept apart for the interface. Editing without a new key keeps the stored
 * one. A new connection starts inactive; `ActivateAiProviderConnection`
 * puts it in use.
 */
final readonly class SaveAiProviderConnection
{
    /**
     * @param  array{provider?: mixed, name?: mixed, models?: mixed, model?: mixed, api_key?: mixed, base_url?: mixed}  $input  `provider` is the slug; `model` the default, one of `models`
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input, ?AiProviderConnection $connection = null): AiProviderConnection
    {
        Gate::forUser($actor)->authorize('super-admin');

        foreach (['name', 'model', 'api_key', 'base_url'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (is_array($input['models'] ?? null)) {
            $input['models'] = array_values(array_unique(array_filter(
                array_map(fn (mixed $model): mixed => is_string($model) ? trim($model) : $model, $input['models']),
                fn (mixed $model): bool => $model !== '' && $model !== null,
            )));
        }

        if (blank($input['model'] ?? null) && is_array($input['models'] ?? null)) {
            $input['model'] = $input['models'][0] ?? null;
        }

        $provider = is_string($input['provider'] ?? null)
            ? AiProvider::query()->active()->slug($input['provider'])->first()
            : null;

        /** @var array{provider: string, name: string, models: list<string>, model: string, api_key?: string|null, base_url?: string|null} $validated */
        $validated = Validator::make($input, [
            'provider' => ['required', 'string', Rule::exists('ai_providers', 'slug')->where('is_active', true)],
            'name' => ['required', 'string', 'max:100'],
            'models' => ['required', 'array', 'list', 'max:50'],
            'models.*' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', Rule::in(is_array($input['models'] ?? null) ? $input['models'] : [])],
            'api_key' => [
                // Ollama runs locally without a key; editing keeps the stored key.
                $connection === null && $provider?->slug !== AiProvider::OLLAMA ? 'required' : 'nullable',
                'string', 'min:4', 'max:2000',
            ],
            'base_url' => [$provider?->requires_base_url ? 'required' : 'nullable', 'string', 'url:http,https', 'max:255'],
        ])->validate();

        $connection ??= new AiProviderConnection(['created_by_id' => $actor->id, 'is_active' => false]);

        $connection->fill([
            'ai_provider_id' => AiProvider::idFor($validated['provider']),
            'name' => $validated['name'],
            'model' => $validated['model'],
            'models' => $validated['models'],
            'base_url' => filled($validated['base_url'] ?? null) ? $validated['base_url'] : null,
        ]);

        if (filled($validated['api_key'] ?? null)) {
            $connection->fill([
                'api_key' => $validated['api_key'],
                'api_key_last_four' => substr($validated['api_key'], -4),
            ]);
        }

        $connection->save();

        return $connection;
    }
}
