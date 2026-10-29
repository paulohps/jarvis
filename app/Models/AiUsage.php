<?php

namespace App\Models;

use Database\Factories\AiUsageFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call to the AI (RecordAiUsage) and the tokens it took. `input_tokens`
 * counts every input token, the ones read from or written to the provider's
 * prompt cache included (`cache_read_tokens`, `cache_write_tokens`);
 * `output_tokens` counts the reasoning ones (`reasoning_tokens`) too.
 *
 * @property int $id
 * @property string $invocation_id
 * @property int|null $ai_provider_connection_id
 * @property int $ai_provider_id
 * @property int|null $ai_purpose_id
 * @property int|null $user_id
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property bool $succeeded
 * @property string|null $error
 * @property Carbon $created_at
 * @property-read AiProviderConnection|null $connection
 * @property-read AiProvider $provider
 * @property-read AiPurpose|null $purpose
 * @property-read User|null $user
 */
#[Fillable([
    'invocation_id', 'ai_provider_connection_id', 'ai_provider_id', 'ai_purpose_id', 'user_id', 'model',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens',
    'succeeded', 'error', 'created_at',
])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'succeeded' => 'boolean',
        ];
    }

    /**
     * The calls made in the period (both ends included).
     *
     * @param  Builder<self>  $query
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $until): void
    {
        $query->whereBetween($this->qualifyColumn('created_at'), [$from, $until]);
    }

    /**
     * @return BelongsTo<AiProviderConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiProviderConnection::class, 'ai_provider_connection_id')->withTrashed();
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * @return BelongsTo<AiPurpose, $this>
     */
    public function purpose(): BelongsTo
    {
        return $this->belongsTo(AiPurpose::class, 'ai_purpose_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
