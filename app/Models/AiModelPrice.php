<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What one model of a provider costs, in US dollars per million tokens. The
 * cache prices are optional: without them, cached tokens cost as input.
 *
 * Removed with a soft delete (SaveAiModelPrice): it still prices the usage
 * of before its removal (pricesUsageAt()), so past spend stays; typed again,
 * the same row comes back with the new prices.
 *
 * @property int $id
 * @property int $ai_provider_id
 * @property string $model
 * @property string $input_per_million
 * @property string $output_per_million
 * @property string|null $cache_read_per_million
 * @property string|null $cache_write_per_million
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AiProvider $provider
 */
#[Fillable(['ai_provider_id', 'model', 'input_per_million', 'output_per_million', 'cache_read_per_million', 'cache_write_per_million'])]
class AiModelPrice extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_per_million' => 'decimal:4',
            'output_per_million' => 'decimal:4',
            'cache_read_per_million' => 'decimal:4',
            'cache_write_per_million' => 'decimal:4',
        ];
    }

    /**
     * The cost, in US dollars, of the tokens: the input ones not read from
     * nor written to the cache at the input price, the cached ones at their
     * own price (the input one when there is none) and the output ones.
     */
    public function costOf(int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): float
    {
        $input = (float) $this->input_per_million;
        $uncachedInputTokens = max(0, $inputTokens - $cacheReadTokens - $cacheWriteTokens);

        return (
            $uncachedInputTokens * $input
            + $cacheReadTokens * (float) ($this->cache_read_per_million ?? $input)
            + $cacheWriteTokens * (float) ($this->cache_write_per_million ?? $input)
            + $outputTokens * (float) $this->output_per_million
        ) / 1_000_000;
    }

    /**
     * Whether it prices a use of the AI made at `$at`: always, unless it was
     * removed by then.
     */
    public function pricesUsageAt(?CarbonInterface $at): bool
    {
        return $this->deleted_at === null || ($at !== null && $at->lessThan($this->deleted_at));
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
