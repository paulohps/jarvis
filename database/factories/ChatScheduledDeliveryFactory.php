<?php

namespace Database\Factories;

use App\Models\ChatScheduledDelivery;
use App\Models\ChatScheduledMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatScheduledDelivery>
 */
class ChatScheduledDeliveryFactory extends Factory
{
    /**
     * A sending of a scheduled message, a minute ago, sent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_scheduled_message_id' => ChatScheduledMessage::factory(),
            'user_id' => fn (array $attributes): int => ChatScheduledMessage::query()->whereKey($attributes['chat_scheduled_message_id'])->firstOrFail()->user_id,
            'chat_conversation_id' => fn (array $attributes): int => ChatScheduledMessage::query()->whereKey($attributes['chat_scheduled_message_id'])->firstOrFail()->chat_conversation_id,
            'conversation_name' => fake()->name(),
            'slot_at' => now()->subMinute(),
            'body' => fake()->sentence(),
            'status' => ChatScheduledDelivery::SENT,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ChatScheduledDelivery::FAILED, 'error' => 'The channel refused it.']);
    }
}
