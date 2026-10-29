<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\AiProviderConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The credentials of a remote AI provider. At most one is active (partial
 * unique index): the connection in use, for every use of the AI that was
 * not given its own (AiPurposeAssignment, resolved by AiRoute). `models` are
 * the models the key offers (AiModel cases or any other id) and `model` is
 * the default among them. `api_key` is
 * encrypted at rest and never serialized; `api_key_last_four` is what the
 * interface shows.
 *
 * Removed in the admin area with a soft delete (DeleteAiProviderConnection),
 * so the AI usage it served keeps it.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $ai_provider_id
 * @property string $name
 * @property string $model
 * @property list<string> $models
 * @property string|null $api_key
 * @property string|null $api_key_last_four
 * @property string|null $base_url
 * @property bool $is_active
 * @property int $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AiProvider $provider
 * @property-read User $createdBy
 */
#[Fillable(['ai_provider_id', 'name', 'model', 'models', 'api_key', 'api_key_last_four', 'base_url', 'is_active', 'created_by_id'])]
#[Hidden(['api_key'])]
class AiProviderConnection extends Model
{
    /** @use HasFactory<AiProviderConnectionFactory> */
    use HasFactory, HasFriendlyId, SoftDeletes;

    public static function idPrefix(): string
    {
        return 'aic';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'models' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The connection in use, if any (at most one is active).
     */
    public static function current(): ?self
    {
        return self::query()->active()->with('provider')->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * The `laravel/ai` provider configuration of this connection, for
     * `Ai::build()`: the driver, the decrypted key, the base URL when there
     * is one and the model (this connection's own unless another is given)
     * as the default text model.
     *
     * @return array{driver: string, key: string, url?: string, models: array{text: array{default: string}}}
     */
    public function providerConfig(?string $model = null): array
    {
        $config = [
            'driver' => $this->provider->slug,
            'key' => (string) $this->api_key,
            'models' => ['text' => ['default' => $model ?? $this->model]],
        ];

        if (filled($this->base_url)) {
            $config['url'] = $this->base_url;
        }

        return $config;
    }

    /**
     * Whether the key offers the model.
     */
    public function offersModel(string $model): bool
    {
        return in_array($model, $this->models, true);
    }

    /**
     * The key as the interface shows it: only its last four characters.
     */
    public function maskedApiKey(): ?string
    {
        return $this->api_key_last_four === null ? null : '••••'.$this->api_key_last_four;
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
