<?php

namespace App\Services\Voice;

use Carbon\CarbonInterface;

/**
 * What a voice command came to (US-10.2, US-10.3), as the voice endpoints
 * answer it: the action, a status and a translated message the app shows
 * and speaks (TTS).
 *
 * - `done`: the Action ran;
 * - `needs_confirmation`: nothing ran yet; `confirmation` carries the token
 *   to confirm with (`whatsapp.send`, always) and a summary;
 * - `needs_clarification`: more than one candidate; `clarification` names
 *   the parameter and its options, to be sent back as the action's
 *   parameter (`parameters` is the action as understood);
 * - `forbidden`: the action's module is switched off for the user;
 * - `failed`: nothing was understood, found or allowed by the Action.
 */
final readonly class VoiceResult
{
    public const string DONE = 'done';

    public const string NEEDS_CONFIRMATION = 'needs_confirmation';

    public const string NEEDS_CLARIFICATION = 'needs_clarification';

    public const string FORBIDDEN = 'forbidden';

    public const string FAILED = 'failed';

    /**
     * @param  array{parameter: string, options: list<array{id: string, label: string}>}|null  $clarification
     * @param  array{token: string, expires_at: string, summary: array<string, string|null>}|null  $confirmation
     */
    private function __construct(
        public ?VoiceAction $action,
        public string $status,
        public string $message,
        public ?array $clarification = null,
        public ?array $confirmation = null,
    ) {}

    public static function done(VoiceAction $action, string $message): self
    {
        return new self($action, self::DONE, $message);
    }

    public static function forbidden(VoiceAction $action, string $message): self
    {
        return new self($action, self::FORBIDDEN, $message);
    }

    public static function failed(?VoiceAction $action, string $message): self
    {
        return new self($action, self::FAILED, $message);
    }

    /**
     * @param  list<array{id: string, label: string}>  $options
     */
    public static function needsClarification(VoiceAction $action, string $parameter, array $options, string $message): self
    {
        return new self($action, self::NEEDS_CLARIFICATION, $message, clarification: [
            'parameter' => $parameter,
            'options' => $options,
        ]);
    }

    /**
     * @param  array<string, string|null>  $summary
     */
    public static function needsConfirmation(VoiceAction $action, string $token, CarbonInterface $expiresAt, array $summary, string $message): self
    {
        return new self($action, self::NEEDS_CONFIRMATION, $message, confirmation: [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601ZuluString(),
            'summary' => $summary,
        ]);
    }

    /**
     * The response body of the voice endpoints.
     *
     * @return array{action: string|null, status: string, message: string, parameters: array<string, string|null>|null, clarification: array{parameter: string, options: list<array{id: string, label: string}>}|null, confirmation: array{token: string, expires_at: string, summary: array<string, string|null>}|null}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action?->name,
            'status' => $this->status,
            'message' => $this->message,
            'parameters' => $this->action?->parameters,
            'clarification' => $this->clarification,
            'confirmation' => $this->confirmation,
        ];
    }
}
