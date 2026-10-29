<?php

namespace App\Models;

use Database\Factories\InvoiceDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to e-mail an invoice: by the scheduler (`triggered_by_id`
 * null) or by a manual send / resend (US-6.7).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $delivery_status_id
 * @property int|null $triggered_by_id
 * @property int $attempts
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 * @property-read DeliveryStatus $status
 * @property-read User|null $triggeredBy
 */
#[Fillable(['invoice_id', 'delivery_status_id', 'triggered_by_id', 'attempts', 'error_message', 'sent_at'])]
class InvoiceDelivery extends Model
{
    /** @use HasFactory<InvoiceDeliveryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<DeliveryStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(DeliveryStatus::class, 'delivery_status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }
}
