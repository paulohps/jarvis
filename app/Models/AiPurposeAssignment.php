<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The connection, and optionally another model than the connection's own,
 * one use of the AI goes through. Removing the connection removes it, and
 * the use goes back to the connection in use.
 *
 * @property int $id
 * @property int $ai_purpose_id
 * @property int $ai_provider_connection_id
 * @property string|null $model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiPurpose $purpose
 * @property-read AiProviderConnection $connection
 */
#[Fillable(['ai_purpose_id', 'ai_provider_connection_id', 'model'])]
class AiPurposeAssignment extends Model
{
    /**
     * @return BelongsTo<AiPurpose, $this>
     */
    public function purpose(): BelongsTo
    {
        return $this->belongsTo(AiPurpose::class, 'ai_purpose_id');
    }

    /**
     * @return BelongsTo<AiProviderConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiProviderConnection::class, 'ai_provider_connection_id');
    }
}
