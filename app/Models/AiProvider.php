<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The remote AI providers. `slug` is the driver name `laravel/ai` accepts.
 *
 * @property bool $requires_base_url
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'requires_base_url', 'is_active'])]
class AiProvider extends Lookup
{
    public const string ANTHROPIC = 'anthropic';

    public const string DEEPSEEK = 'deepseek';

    public const string GEMINI = 'gemini';

    public const string GROQ = 'groq';

    public const string MISTRAL = 'mistral';

    public const string OLLAMA = 'ollama';

    public const string OPENAI = 'openai';

    public const string OPENAI_COMPATIBLE = 'openai-compatible';

    public const string OPENROUTER = 'openrouter';

    public const string XAI = 'xai';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_base_url' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AiProviderConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(AiProviderConnection::class);
    }
}
