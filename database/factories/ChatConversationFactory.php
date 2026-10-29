<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    use ResolvesLookups;

    /**
     * The entry of a Slack channel of a new account of the user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_provider_id' => fn (): int => self::lookupId(ChatProvider::class, ChatProvider::SLACK),
            'conversable_type' => 'slack_conversation',
            'conversable_id' => fn (array $attributes) => SlackConversation::factory()->for(
                SlackAccount::factory()->for(User::query()->whereKey($attributes['user_id'])->firstOrFail()),
                'account',
            ),
            'kind' => ChatConversation::KIND_CHANNEL,
            'name' => fake()->unique()->slug(1),
            'unread_count' => 0,
            'marked_unread' => false,
            'last_message_at' => now()->subMinutes(5),
            'last_message' => ['text' => fake()->sentence(), 'from_me' => false, 'sender' => fake()->firstName(), 'kind' => 'text'],
        ];
    }

    /**
     * The entry of the given Slack conversation.
     */
    public function forSlack(SlackConversation $slackConversation): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $slackConversation->account->user_id,
            'conversable_type' => 'slack_conversation',
            'conversable_id' => $slackConversation->id,
            'kind' => $slackConversation->chatKind(),
            'name' => $slackConversation->name,
        ]);
    }

    public function direct(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => ChatConversation::KIND_DIRECT, 'name' => fake()->firstName()]);
    }

    public function pinned(): static
    {
        return $this->state(fn (array $attributes) => ['pinned_at' => now()]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
