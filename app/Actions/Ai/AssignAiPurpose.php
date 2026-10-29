<?php

namespace App\Actions\Ai;

use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\AiPurposeAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The super admin picks the connection, and optionally one of the other
 * models it offers than its default, one use of the AI goes through (voice
 * commands, WhatsApp replies…). No connection puts the use back on the connection in
 * use. Read by AiRoute.
 */
final readonly class AssignAiPurpose
{
    /**
     * @param  string|null  $connectionId  the connection's friendly id; null for the connection in use
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, string $purpose, ?string $connectionId, ?string $model): ?AiPurposeAssignment
    {
        Gate::forUser($actor)->authorize('super-admin');

        $purposeId = AiPurpose::idFor($purpose);

        if (blank($connectionId)) {
            AiPurposeAssignment::query()->where('ai_purpose_id', $purposeId)->delete();

            return null;
        }

        $connection = AiProviderConnection::query()->where('friendly_id', $connectionId)->first();

        /** @var array{connection: string, model: string|null} $validated */
        $validated = Validator::make(
            ['connection' => $connectionId, 'model' => filled($model) ? trim((string) $model) : null],
            [
                'connection' => ['required', 'string', 'exists:ai_provider_connections,friendly_id'],
                'model' => ['nullable', 'string', Rule::in($connection === null ? [] : $connection->models)],
            ],
            ['model.in' => __('The connection does not offer this model.')],
        )->validate();

        /** @var AiProviderConnection $connection */
        return AiPurposeAssignment::query()->updateOrCreate(
            ['ai_purpose_id' => $purposeId],
            [
                'ai_provider_connection_id' => $connection->id,
                'model' => $validated['model'],
            ],
        );
    }
}
