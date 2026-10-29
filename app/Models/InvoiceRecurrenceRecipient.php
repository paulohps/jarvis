<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An e-mail address every invoice of a recurrence is sent to. The 1–10
 * limit (US-6.3) is validated by the action, not the database.
 *
 * @property int $id
 * @property int $invoice_recurrence_id
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InvoiceRecurrence $invoiceRecurrence
 */
#[Fillable(['invoice_recurrence_id', 'email'])]
class InvoiceRecurrenceRecipient extends Model
{
    /**
     * @return BelongsTo<InvoiceRecurrence, $this>
     */
    public function invoiceRecurrence(): BelongsTo
    {
        return $this->belongsTo(InvoiceRecurrence::class);
    }
}
