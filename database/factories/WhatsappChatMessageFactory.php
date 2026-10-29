<?php

namespace Database\Factories;

use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsappChatMessage>
 */
class WhatsappChatMessageFactory extends Factory
{
    /**
     * A text received from a contact an hour ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_target_id' => WhatsappTarget::factory(),
            'external_id' => Str::upper(Str::random(20)),
            'from_me' => false,
            'message_type' => 'conversation',
            'body' => fake()->sentence(),
            'sent_at' => now()->subHour(),
        ];
    }

    /**
     * Sent by the owner of the number.
     */
    public function fromMe(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_me' => true,
        ]);
    }

    /**
     * A voice note: no text.
     */
    public function audio(): static
    {
        return $this->state(fn (array $attributes) => [
            'message_type' => 'audioMessage',
            'body' => null,
        ]);
    }

    /**
     * A poll the contact made: "Vamos?" with "Sim" and "Não", one choice.
     */
    public function poll(): static
    {
        return $this->state(fn (array $attributes) => [
            'message_type' => 'pollCreationMessageV3',
            'body' => 'Vamos?',
            'payload' => ['poll' => ['name' => 'Vamos?', 'options' => ['Sim', 'Não'], 'selectable_count' => 1]],
        ]);
    }
}
