<?php

namespace Database\Factories;

use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappChatReceipt>
 */
class WhatsappChatReceiptFactory extends Factory
{
    /**
     * Delivered to a contact a minute ago, not read yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_chat_message_id' => WhatsappChatMessage::factory()->fromMe(),
            'participant' => '55119'.fake()->numerify('########').'@s.whatsapp.net',
            'delivered_at' => now()->subMinute(),
            'read_at' => null,
            'played_at' => null,
        ];
    }
}
