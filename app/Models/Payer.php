<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasFriendlyId;
use Database\Factories\PayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Who an invoice is billed to (US-6.1), shared by the members of its
 * workspace with the invoices module; the workspace is fixed when it is
 * created. Invoices keep a snapshot of the name and address, so editing or
 * deleting a payer never changes a sent invoice.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $friendly_id
 * @property string $name
 * @property string $address
 * @property int $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $createdBy
 */
#[Fillable(['workspace_id', 'name', 'address', 'created_by_id'])]
class Payer extends Model
{
    /** @use HasFactory<PayerFactory> */
    use BelongsToWorkspace, HasFactory, HasFriendlyId, SoftDeletes;

    public static function idPrefix(): string
    {
        return 'pay';
    }

    public static function workspaceModule(): string
    {
        return Module::INVOICES;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<InvoiceRecurrence, $this>
     */
    public function invoiceRecurrences(): HasMany
    {
        return $this->hasMany(InvoiceRecurrence::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
