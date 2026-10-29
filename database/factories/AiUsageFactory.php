<?php

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\AiUsage;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A successful call through an OpenAI connection, without cache, made now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invocation_id' => fn (): string => (string) Str::uuid7(),
            'ai_provider_connection_id' => AiProviderConnection::factory(),
            'ai_provider_id' => fn (): int => static::lookupId(AiProvider::class, AiProvider::OPENAI, ['name' => 'OpenAI']),
            'ai_purpose_id' => null,
            'user_id' => null,
            'model' => 'gpt-6-luna',
            'input_tokens' => fake()->numberBetween(200, 4000),
            'output_tokens' => fake()->numberBetween(10, 400),
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
            'succeeded' => true,
            'error' => null,
            'created_at' => now(),
        ];
    }

    /**
     * A call that failed before any token was counted.
     */
    public function failed(string $error = 'The provider did not answer.'): static
    {
        return $this->state(fn (array $attributes) => [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'succeeded' => false,
            'error' => $error,
        ]);
    }
}
