<?php

namespace Database\Factories;

use App\Models\SlackAccount;
use App\Models\SlackConversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlackConversation>
 */
class SlackConversationFactory extends Factory
{
    /**
     * A public channel of a new account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slack_account_id' => SlackAccount::factory(),
            'channel_id' => 'C'.Str::upper(Str::random(10)),
            'kind' => SlackConversation::KIND_PUBLIC,
            'name' => fake()->unique()->slug(1),
            'is_archived' => false,
            'is_member' => true,
            'history_loaded_at' => now()->subHour(),
        ];
    }

    /**
     * A DM with the person.
     */
    public function directWith(?string $slackUserId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_id' => 'D'.Str::upper(Str::random(10)),
            'kind' => SlackConversation::KIND_IM,
            'name' => null,
            'counterpart_user_id' => $slackUserId ?? 'U'.Str::upper(Str::random(10)),
        ]);
    }

    public function groupDirect(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_id' => 'G'.Str::upper(Str::random(10)),
            'kind' => SlackConversation::KIND_MPIM,
            'name' => 'mpdm-ana--bia--caio-1',
        ]);
    }

    public function privateChannel(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => SlackConversation::KIND_PRIVATE,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['is_archived' => true]);
    }
}
