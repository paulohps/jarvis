<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An e-mail address an invoice is sent to, copied from the recurrence when
 * the invoice is generated.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
#[Fillable(['invoice_id', 'email'])]
class InvoiceRecipient extends Model
{
    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
