<?php

namespace Database\Factories;

use App\Models\WhatsappDraftConversation;
use App\Models\WhatsappDraftMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappDraftMessage>
 */
class WhatsappDraftMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_draft_conversation_id' => WhatsappDraftConversation::factory(),
            'role' => WhatsappDraftMessage::USER,
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A message the AI wrote.
     */
    public function fromAssistant(): static
    {
        return $this->state(['role' => WhatsappDraftMessage::ASSISTANT]);
    }
}
