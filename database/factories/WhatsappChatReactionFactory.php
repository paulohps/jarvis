<?php

namespace Database\Factories;

use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappChatReaction>
 */
class WhatsappChatReactionFactory extends Factory
{
    /**
     * A contact's 👍 a minute ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_chat_message_id' => WhatsappChatMessage::factory(),
            'reactor' => '55119'.fake()->numerify('########').'@s.whatsapp.net',
            'emoji' => '👍',
            'reacted_at' => now()->subMinute(),
        ];
    }

    /**
     * The owner of the number's.
     */
    public function mine(): static
    {
        return $this->state(fn (array $attributes) => [
            'reactor' => WhatsappChatReaction::ME,
        ]);
    }
}
