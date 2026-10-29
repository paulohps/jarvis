<?php

namespace App\Actions\Ai;

use App\Models\AiModelPrice;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The super admin types what a model of a provider costs, in US dollars per
 * million tokens, from the provider's price page; the usage report
 * estimates the spend from it. Empty input and output prices remove it, a
 * soft delete: the usage of before keeps its cost (AiModelPrice). Typed
 * again, a removed price comes back with the new values.
 */
final readonly class SaveAiModelPrice
{
    /**
     * @param  array{input: string|float|null, output: string|float|null, cache_read?: string|float|null, cache_write?: string|float|null}  $prices
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, string $provider, string $model, array $prices): ?AiModelPrice
    {
        Gate::forUser($actor)->authorize('super-admin');

        $providerId = AiProvider::idFor($provider);
        $prices = array_map(fn (mixed $price): mixed => blank($price) ? null : $price, $prices);

        if ($prices['input'] === null && $prices['output'] === null) {
            AiModelPrice::query()->where('ai_provider_id', $providerId)->where('model', $model)->delete();

            return null;
        }

        /** @var array{input: string, output: string, cache_read: string|null, cache_write: string|null} $validated */
        $validated = Validator::make($prices, [
            'input' => ['required', 'numeric', 'min:0', 'max:100000'],
            'output' => ['required', 'numeric', 'min:0', 'max:100000'],
            'cache_read' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cache_write' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ])->validate();

        $price = AiModelPrice::withTrashed()->firstOrNew(['ai_provider_id' => $providerId, 'model' => $model]);
        $price->fill([
            'input_per_million' => $validated['input'],
            'output_per_million' => $validated['output'],
            'cache_read_per_million' => $validated['cache_read'] ?? null,
            'cache_write_per_million' => $validated['cache_write'] ?? null,
        ]);
        $price->deleted_at = null;
        $price->save();

        return $price;
    }
}
