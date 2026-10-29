<?php

namespace Database\Factories;

use App\Models\DeliveryStatus;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonInterface;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsappDelivery>
 */
class WhatsappDeliveryFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A pending manual send (no message) to a contact of a new account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_account_id' => WhatsappAccount::factory(),
            'whatsapp_target_id' => fn (array $attributes) => WhatsappTarget::factory()->state(['whatsapp_account_id' => $attributes['whatsapp_account_id']]),
            'whatsapp_message_id' => null,
            'whatsapp_delivery_source_id' => fn (): int => static::lookupId(WhatsappDeliverySource::class, WhatsappDeliverySource::MANUAL),
            'delivery_status_id' => fn (): int => self::statusId(DeliveryStatus::PENDING),
            'body' => fake()->sentence(),
            'scheduled_for' => null,
            'attempts' => 0,
            'external_message_id' => null,
            'error_message' => null,
            'triggered_by_id' => null,
            'sent_at' => null,
        ];
    }

    /**
     * A run of a scheduled message: to one of its targets, for the slot.
     */
    public function scheduled(WhatsappMessage $message, ?CarbonInterface $slot = null): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_account_id' => $message->whatsapp_account_id,
            'whatsapp_target_id' => $message->targets()->value('whatsapp_targets.id')
                ?? WhatsappTarget::factory()->state(['whatsapp_account_id' => $message->whatsapp_account_id]),
            'whatsapp_message_id' => $message->id,
            'whatsapp_delivery_source_id' => static::lookupId(WhatsappDeliverySource::class, WhatsappDeliverySource::SCHEDULED),
            'body' => $message->body,
            'scheduled_for' => $slot ?? now()->startOfMinute(),
        ]);
    }

    /**
     * Accepted by the Evolution API.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_status_id' => self::statusId(DeliveryStatus::SENT),
            'attempts' => 1,
            'external_message_id' => Str::upper(Str::random(20)),
            'sent_at' => now(),
        ]);
    }

    /**
     * Gave up after the retries.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_status_id' => self::statusId(DeliveryStatus::FAILED),
            'attempts' => 3,
            'error_message' => 'Evolution API: instance not connected',
        ]);
    }

    private static function statusId(string $slug): int
    {
        return static::lookupId(DeliveryStatus::class, $slug);
    }
}
