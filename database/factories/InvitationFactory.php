<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Locale;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A pending invitation sent by a super admin.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'locale_id' => fn (): int => static::lookupId(Locale::class, Locale::EN, ['name' => 'English']),
            'workspace_id' => fn (): int => WorkspaceFactory::household()->id,
            'invited_by_id' => User::factory()->superAdmin(),
            'expires_at' => now()->addDays(Invitation::VALIDITY_DAYS),
        ];
    }

    /**
     * Open but past its validity.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Accepted by the user created from it.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
            'accepted_user_id' => User::factory()->state(['email' => $attributes['email']]),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
