<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The model stopped at the most tokens it could write before the answer
 * was over: what it wrote is cut in the middle, so it is not used. A model
 * that reasons spends that same budget on its reasoning.
 */
final class AiAnswerCutShort extends RuntimeException
{
    public function __construct(string $model, int $outputTokens, int $reasoningTokens)
    {
        parent::__construct("The answer of {$model} was cut short at {$outputTokens} output tokens ({$reasoningTokens} of them reasoning).");
    }
}
