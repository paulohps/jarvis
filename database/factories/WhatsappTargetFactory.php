<?php

namespace Database\Factories;

use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappTarget>
 */
class WhatsappTargetFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A contact synced from a new account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_account_id' => WhatsappAccount::factory(),
            'whatsapp_target_type_id' => fn (): int => self::typeId(WhatsappTargetType::CONTACT),
            'jid' => self::contactJid(),
            'name' => fake()->firstName(),
            'is_synced' => true,
        ];
    }

    public function contact(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_target_type_id' => self::typeId(WhatsappTargetType::CONTACT),
            'jid' => self::contactJid(),
        ]);
    }

    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_target_type_id' => self::typeId(WhatsappTargetType::GROUP),
            'jid' => '1203630'.fake()->unique()->numerify('###########').'@g.us',
            'name' => fake()->words(2, true),
        ]);
    }

    /**
     * A number typed in E.164 instead of synced, so it has no name.
     */
    public function manual(): static
    {
        return $this->contact()->state(fn (array $attributes) => [
            'name' => null,
            'is_synced' => false,
        ]);
    }

    private static function contactJid(): string
    {
        return '55119'.fake()->unique()->numerify('########').'@s.whatsapp.net';
    }

    private static function typeId(string $slug): int
    {
        return static::lookupId(WhatsappTargetType::class, $slug);
    }
}
