<?php

namespace App\Services\Voice;

use Illuminate\Database\Eloquent\Model;

/**
 * What a spoken name resolved to (US-10.3): one record, several candidates
 * too close to pick from (the options to ask about), or nothing.
 */
final readonly class VoiceResolution
{
    /**
     * @param  list<array{id: string, label: string}>  $options
     */
    private function __construct(
        public ?Model $match,
        public array $options,
    ) {}

    public static function resolved(Model $match): self
    {
        return new self($match, []);
    }

    /**
     * @param  list<array{id: string, label: string}>  $options
     */
    public static function ambiguous(array $options): self
    {
        return new self(null, $options);
    }

    public static function notFound(): self
    {
        return new self(null, []);
    }

    public function isResolved(): bool
    {
        return $this->match !== null;
    }

    public function isAmbiguous(): bool
    {
        return $this->match === null && $this->options !== [];
    }
}
