<?php

namespace Database\Factories;

use App\Models\WhatsappChatMessage;
use App\Models\WhatsappPollVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappPollVote>
 */
class WhatsappPollVoteFactory extends Factory
{
    /**
     * A contact's vote for "Sim" a minute ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_chat_message_id' => WhatsappChatMessage::factory()->poll(),
            'voter' => '55119'.fake()->numerify('########').'@s.whatsapp.net',
            'options' => ['Sim'],
            'voted_at' => now()->subMinute(),
        ];
    }

    /**
     * The owner of the number's.
     */
    public function mine(): static
    {
        return $this->state(fn (array $attributes) => [
            'voter' => WhatsappPollVote::ME,
        ]);
    }
}
