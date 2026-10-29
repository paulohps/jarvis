<?php

namespace App\Actions\Ai;

use App\Ai\Agents\ConnectionCheck;
use App\Models\AiProviderConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * The super admin's "test connection" (US-2.3): one minimal prompt through
 * the connection (`ConnectionCheck`). Any failure — a wrong key, an unknown
 * model, an unreachable URL — becomes the provider's error message instead
 * of an exception; the key never appears in it.
 */
final readonly class TestAiProviderConnection
{
    /**
     * @return array{successful: bool, message: string}
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, AiProviderConnection $connection): array
    {
        Gate::forUser($actor)->authorize('super-admin');

        $connection->loadMissing('provider');

        try {
            (new ConnectionCheck($connection, $actor))->prompt(ConnectionCheck::PROMPT);
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'message' => $this->errorMessage($exception, $connection),
            ];
        }

        return [
            'successful' => true,
            'message' => __('The connection works: :provider answered with :model.', [
                'provider' => __($connection->provider->name),
                'model' => $connection->model,
            ]),
        ];
    }

    /**
     * The provider's message, with the key masked should the provider echo it.
     */
    private function errorMessage(Throwable $exception, AiProviderConnection $connection): string
    {
        $message = trim($exception->getMessage()) !== '' ? $exception->getMessage() : $exception::class;

        if (filled($connection->api_key)) {
            $message = str_replace((string) $connection->api_key, (string) $connection->maskedApiKey(), $message);
        }

        return Str::limit($message, 500);
    }
}
