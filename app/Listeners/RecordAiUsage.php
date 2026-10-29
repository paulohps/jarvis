<?php

namespace App\Listeners;

use App\Ai\Contracts\ReportsAiUsage;
use App\Models\AiPurpose;
use App\Models\AiUsage;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * Writes down every call of an agent that reports its usage
 * (ReportsAiUsage) — the tokens it took when it answered, the error when it
 * failed for good — for the usage report of the admin area. Writing it
 * down never breaks the call: a failure here is only reported.
 */
final class RecordAiUsage
{
    public function handlePrompted(AgentPrompted $event): void
    {
        $this->record($event->invocationId, $event->prompt, $event->response->usage);
    }

    public function handleFailed(AgentFailed $event): void
    {
        $this->record($event->invocationId, $event->prompt, null, $event->exception);
    }

    private function record(string $invocationId, AgentPrompt $prompt, ?TextUsage $usage, ?Throwable $exception = null): void
    {
        $agent = $prompt->agent;

        if (! $agent instanceof ReportsAiUsage) {
            return;
        }

        try {
            $connection = $agent->usageConnection();
            $purpose = $agent->usagePurpose();

            AiUsage::query()->create([
                'invocation_id' => $invocationId,
                'ai_provider_connection_id' => $connection->id,
                'ai_provider_id' => $connection->ai_provider_id,
                'ai_purpose_id' => $purpose === null ? null : AiPurpose::idFor($purpose),
                'user_id' => $agent->usageUser()?->id,
                'model' => $prompt->model,
                'input_tokens' => $usage->inputTokens ?? 0,
                'output_tokens' => $usage->outputTokens ?? 0,
                'cache_read_tokens' => $usage->cacheReadInputTokens ?? 0,
                'cache_write_tokens' => $usage->cacheWriteInputTokens ?? 0,
                'reasoning_tokens' => $usage->reasoningTokens ?? 0,
                'succeeded' => $exception === null,
                'error' => $exception === null ? null : Str::limit(class_basename($exception).': '.$exception->getMessage(), 250),
                'created_at' => now(),
            ]);
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
