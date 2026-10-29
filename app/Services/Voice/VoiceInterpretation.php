<?php

namespace App\Services\Voice;

/**
 * What the remote interpreter understood of a spoken text (US-10.2): the
 * action it mapped the text to, if any, and the model's own reply.
 */
final readonly class VoiceInterpretation
{
    public function __construct(
        public ?VoiceAction $action,
        public string $reply,
    ) {}
}
