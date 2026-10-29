<?php

namespace App\Enums;

use App\Models\AiProvider;

/**
 * The main text models of each AI provider, as the API names them (checked
 * against each provider's docs in October 2026). They are what a connection
 * offers to pick from; a connection may also list models not here (Ollama,
 * OpenAI compatible, OpenRouter or a model newer than this list).
 */
enum AiModel: string
{
    case ClaudeFable51 = 'claude-fable-5-1';
    case ClaudeOpus55 = 'claude-opus-5-5';
    case ClaudeSonnet55 = 'claude-sonnet-5-5';
    case ClaudeHaiku55 = 'claude-haiku-5-5';

    case DeepseekFlash = 'deepseek-flash';
    case DeepseekV4Pro = 'deepseek-v4-pro';

    case Gemini31ProPreview = 'gemini-3.1-pro-preview';
    case Gemini38Flash = 'gemini-3.8-flash';
    case Gemini35FlashLite = 'gemini-3.5-flash-lite';

    case GroqGptOss120b = 'openai/gpt-oss-120b';
    case GroqGptOss20b = 'openai/gpt-oss-20b';
    case GroqLlama3370bVersatile = 'llama-3.3-70b-versatile';

    case MistralLargeLatest = 'mistral-large-latest';
    case MistralMediumLatest = 'mistral-medium-latest';
    case MistralSmallLatest = 'mistral-small-latest';

    case Gpt6Astra = 'gpt-6-astra';
    case Gpt61Sol = 'gpt-6.1-sol';
    case Gpt6Luna = 'gpt-6-luna';
    case Gpt55 = 'gpt-5.5';

    case OpenrouterAuto = 'openrouter/auto';

    case Grok47 = 'grok-4.7';
    case Grok43 = 'grok-4.3';
    case Grok420NonReasoning = 'grok-4.20-0309-non-reasoning';

    /**
     * The provider (an AiProvider slug) that serves the model.
     */
    public function provider(): string
    {
        return match ($this) {
            self::ClaudeFable51, self::ClaudeOpus55, self::ClaudeSonnet55, self::ClaudeHaiku55 => AiProvider::ANTHROPIC,
            self::DeepseekFlash, self::DeepseekV4Pro => AiProvider::DEEPSEEK,
            self::Gemini31ProPreview, self::Gemini38Flash, self::Gemini35FlashLite => AiProvider::GEMINI,
            self::GroqGptOss120b, self::GroqGptOss20b, self::GroqLlama3370bVersatile => AiProvider::GROQ,
            self::MistralLargeLatest, self::MistralMediumLatest, self::MistralSmallLatest => AiProvider::MISTRAL,
            self::Gpt6Astra, self::Gpt61Sol, self::Gpt6Luna, self::Gpt55 => AiProvider::OPENAI,
            self::OpenrouterAuto => AiProvider::OPENROUTER,
            self::Grok47, self::Grok43, self::Grok420NonReasoning => AiProvider::XAI,
        };
    }

    /**
     * The name the interface shows.
     */
    public function label(): string
    {
        return match ($this) {
            self::ClaudeFable51 => 'Claude Fable 5.1',
            self::ClaudeOpus55 => 'Claude Opus 5.5',
            self::ClaudeSonnet55 => 'Claude Sonnet 5.5',
            self::ClaudeHaiku55 => 'Claude Haiku 5.5',
            self::DeepseekFlash => 'DeepSeek Flash',
            self::DeepseekV4Pro => 'DeepSeek V4 Pro',
            self::Gemini31ProPreview => 'Gemini 3.1 Pro (preview)',
            self::Gemini38Flash => 'Gemini 3.8 Flash',
            self::Gemini35FlashLite => 'Gemini 3.5 Flash-Lite',
            self::GroqGptOss120b => 'GPT-OSS 120B',
            self::GroqGptOss20b => 'GPT-OSS 20B',
            self::GroqLlama3370bVersatile => 'Llama 3.3 70B Versatile',
            self::MistralLargeLatest => 'Mistral Large',
            self::MistralMediumLatest => 'Mistral Medium',
            self::MistralSmallLatest => 'Mistral Small',
            self::Gpt6Astra => 'GPT-6 Astra',
            self::Gpt61Sol => 'GPT-6.1 Sol',
            self::Gpt6Luna => 'GPT-6 Luna',
            self::Gpt55 => 'GPT-5.5',
            self::OpenrouterAuto => 'Auto router',
            self::Grok47 => 'Grok 4.7',
            self::Grok43 => 'Grok 4.3',
            self::Grok420NonReasoning => 'Grok 4.20 (non-reasoning)',
        };
    }

    /**
     * The models of a provider (an AiProvider slug), in the order above.
     *
     * @return list<self>
     */
    public static function forProvider(string $provider): array
    {
        return array_values(array_filter(self::cases(), fn (self $model): bool => $model->provider() === $provider));
    }

    /**
     * The name of a model id: the label when it is one of these, the id itself otherwise.
     */
    public static function labelFor(string $model): string
    {
        return self::tryFrom($model)?->label() ?? $model;
    }
}
