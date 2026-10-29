<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsappAccount>
 */
class WhatsappAccountFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A connected (`open`) account of a new user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nickname' => fake()->randomElement(['Personal', 'Work', 'Home']),
            'instance_name' => 'jarvis-'.Str::lower(Str::random(16)),
            'phone_number' => '+55119'.fake()->numerify('########'),
            'whatsapp_connection_status_id' => fn (): int => self::statusId(WhatsappConnectionStatus::OPEN),
            'connected_at' => now()->subDay(),
            'disconnected_at' => null,
            'last_synced_at' => now()->subHour(),
        ];
    }

    /**
     * Connected and able to send.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_connection_status_id' => self::statusId(WhatsappConnectionStatus::OPEN),
            'connected_at' => now()->subDay(),
            'disconnected_at' => null,
        ]);
    }

    /**
     * Disconnected after having been connected.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_connection_status_id' => self::statusId(WhatsappConnectionStatus::CLOSED),
            'connected_at' => now()->subDays(2),
            'disconnected_at' => now()->subHour(),
        ]);
    }

    /**
     * Waiting for the QR code to be scanned: the number is not known yet.
     */
    public function connecting(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_connection_status_id' => self::statusId(WhatsappConnectionStatus::CONNECTING),
            'phone_number' => null,
            'connected_at' => null,
            'disconnected_at' => null,
            'last_synced_at' => null,
        ]);
    }

    private static function statusId(string $slug): int
    {
        return static::lookupId(WhatsappConnectionStatus::class, $slug);
    }
}
