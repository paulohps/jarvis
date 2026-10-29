<?php

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiProviderConnection>
 */
class AiProviderConnectionFactory extends Factory
{
    use ResolvesLookups;

    /**
     * An inactive OpenAI connection created by a super admin.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $apiKey = 'sk-'.Str::random(40);

        return [
            'ai_provider_id' => fn (): int => static::lookupId(AiProvider::class, AiProvider::OPENAI, ['name' => 'OpenAI']),
            'name' => fake()->words(2, true),
            'model' => 'gpt-6-luna',
            'models' => fn (array $attributes): array => [$attributes['model']],
            'api_key' => $apiKey,
            'api_key_last_four' => substr($apiKey, -4),
            'base_url' => null,
            'is_active' => false,
            'created_by_id' => User::factory()->superAdmin(),
        ];
    }

    /**
     * The one connection in use (at most one is active).
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
}
