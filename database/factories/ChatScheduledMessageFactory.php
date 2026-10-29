<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatScheduledMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatScheduledMessage>
 */
class ChatScheduledMessageFactory extends Factory
{
    /**
     * A text due in an hour, to a conversation of a new user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_conversation_id' => ChatConversation::factory(),
            'user_id' => fn (array $attributes): int => ChatConversation::query()->whereKey($attributes['chat_conversation_id'])->firstOrFail()->user_id,
            'body' => fake()->sentence(),
            'send_at' => now()->addHour(),
        ];
    }

    public function due(): static
    {
        return $this->state(fn (array $attributes) => ['send_at' => now()->subMinute()]);
    }
}
