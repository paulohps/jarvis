<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The last invoice number a workspace used in a month (US-6.5): each
 * workspace counts its own. Incremented inside a transaction under
 * `SELECT ... FOR UPDATE`, so two invoices of a workspace never get the
 * same `YYYYMM-NNN`.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $year_month
 * @property int $last_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'year_month', 'last_number'])]
class InvoiceNumberSequence extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
