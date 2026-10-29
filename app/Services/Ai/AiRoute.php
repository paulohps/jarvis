<?php

namespace App\Services\Ai;

use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\AiPurposeAssignment;

/**
 * The connection and model one use of the AI goes through: the ones picked
 * for that use (AiPurposeAssignment), otherwise the connection in use with
 * its own model. `purpose` is the use (an AiPurpose slug) it was resolved
 * for, which the usage report groups by.
 */
final readonly class AiRoute
{
    public function __construct(
        public AiProviderConnection $connection,
        public string $model,
        public ?string $purpose = null,
    ) {}

    /**
     * The route of the use (an AiPurpose slug); null when nothing was picked
     * for it and no connection is in use.
     */
    public static function for(string $purpose): ?self
    {
        $assignment = AiPurposeAssignment::query()
            ->where('ai_purpose_id', AiPurpose::idFor($purpose))
            ->whereHas('connection')
            ->with('connection.provider')
            ->first();

        if ($assignment !== null) {
            // A model the connection no longer offers falls back to its default.
            $model = filled($assignment->model) && $assignment->connection->offersModel((string) $assignment->model)
                ? (string) $assignment->model
                : $assignment->connection->model;

            return new self($assignment->connection, $model, $purpose);
        }

        $current = AiProviderConnection::current();

        return $current === null ? null : new self($current, $current->model, $purpose);
    }

    /**
     * The `laravel/ai` provider configuration, with this route's model.
     *
     * @return array{driver: string, key: string, url?: string, models: array{text: array{default: string}}}
     */
    public function providerConfig(): array
    {
        return $this->connection->providerConfig($this->model);
    }
}
