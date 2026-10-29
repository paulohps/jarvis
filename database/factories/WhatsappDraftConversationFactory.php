<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappDraftConversation;
use App\Models\WhatsappTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappDraftConversation>
 */
class WhatsappDraftConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'whatsapp_target_id' => WhatsappTarget::factory(),
            'title' => fake()->sentence(3),
            'reads_chat' => true,
            'reply_to' => null,
        ];
    }
}
