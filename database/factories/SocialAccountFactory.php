<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\SocialProvider;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A Google identity linked to a user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'social_provider_id' => fn (): int => static::lookupId(SocialProvider::class, SocialProvider::GOOGLE),
            'provider_user_id' => (string) fake()->unique()->numberBetween(100_000_000_000, 999_999_999_999),
            'email' => fake()->safeEmail(),
        ];
    }
}
