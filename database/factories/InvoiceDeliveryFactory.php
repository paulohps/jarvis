<?php

namespace Database\Factories;

use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDelivery>
 */
class InvoiceDeliveryFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A pending scheduler attempt for a new invoice.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'delivery_status_id' => fn (): int => static::lookupId(DeliveryStatus::class, DeliveryStatus::PENDING),
            'triggered_by_id' => null,
            'attempts' => 0,
            'error_message' => null,
            'sent_at' => null,
        ];
    }
}
