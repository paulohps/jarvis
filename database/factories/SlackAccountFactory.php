<?php

namespace Database\Factories;

use App\Models\SlackAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlackAccount>
 */
class SlackAccountFactory extends Factory
{
    /**
     * A usable Slack workspace of a new user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => 'T'.Str::upper(Str::random(10)),
            'team_name' => fake()->company(),
            'team_domain' => fake()->unique()->slug(2),
            'slack_user_id' => 'U'.Str::upper(Str::random(10)),
            'access_token' => 'xoxp-'.Str::random(40),
            'scopes' => ['channels:history', 'im:history', 'chat:write'],
            'connected_at' => now()->subDay(),
            'synced_at' => now()->subHour(),
        ];
    }

    /**
     * Slack stopped accepting the token.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'failed_at' => now()->subMinute(),
            'last_error' => 'token_revoked',
        ]);
    }
}
