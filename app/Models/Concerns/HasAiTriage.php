<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * How urgent the AI found an unread chat and what it wants from the owner,
 * apart from the user's own `priority`: `ai_urgency` (AI_URGENCIES),
 * `ai_summary`, when it answered (`ai_triaged_at`), the last message it
 * read (`ai_triaged_through`; a newer one makes it out of date) and when
 * it was asked without answering yet (`ai_triage_requested_at`).
 *
 * A chat read (in Jarvis, on the phone, or answered from any device)
 * forgets it all, so that its newer messages may be triaged again.
 *
 * @property int|null $ai_urgency
 * @property string|null $ai_summary
 * @property Carbon|null $ai_triaged_at
 * @property Carbon|null $ai_triaged_through
 * @property Carbon|null $ai_triage_requested_at
 */
trait HasAiTriage
{
    /**
     * How urgent the AI may find an unread chat, the most urgent first, as
     * stored in `ai_urgency`.
     *
     * @var array<int, string>
     */
    public const array AI_URGENCIES = [1 => 'urgent', 2 => 'today', 3 => 'can_wait', 4 => 'info'];

    public const int AI_TRIAGE_WAIT_MINUTES = 10;

    /**
     * @var array<string, array{label: string, color: string}>
     */
    public const array AI_URGENCY_LOOKS = [
        'urgent' => ['label' => 'Urgent', 'color' => 'red'],
        'today' => ['label' => 'Answer today', 'color' => 'orange'],
        'can_wait' => ['label' => 'Can wait', 'color' => 'blue'],
        'info' => ['label' => 'Just info', 'color' => 'gray'],
    ];

    /**
     * The `ai_*` columns, all null once the chat is read.
     *
     * @var list<string>
     */
    public const array AI_TRIAGE_COLUMNS = ['ai_urgency', 'ai_summary', 'ai_triaged_at', 'ai_triaged_through', 'ai_triage_requested_at'];

    abstract public function isUnread(): bool;

    protected static function bootHasAiTriage(): void
    {
        static::saving(function (self $chat): void {
            if ($chat->exists && $chat->isDirty(['unread_count', 'marked_unread']) && ! $chat->isUnread()) {
                $chat->forgetAiTriage();
            }
        });
    }

    protected function initializeHasAiTriage(): void
    {
        $this->mergeCasts([
            'ai_urgency' => 'integer',
            'ai_triaged_at' => 'datetime',
            'ai_triaged_through' => 'datetime',
            'ai_triage_requested_at' => 'datetime',
        ]);
    }

    /**
     * Drops the AI's urgency and summary of the chat (not saved), and any
     * request still waiting.
     */
    public function forgetAiTriage(): static
    {
        return $this->forceFill(array_fill_keys(self::AI_TRIAGE_COLUMNS, null));
    }

    /**
     * The AI's urgency as a slug of AI_URGENCIES, or null when it never
     * triaged the chat.
     */
    public function aiUrgencySlug(): ?string
    {
        return self::AI_URGENCIES[$this->ai_urgency ?? 0] ?? null;
    }

    /**
     * Whether a message came in or went out after the AI read the chat.
     */
    public function aiTriageIsStale(): bool
    {
        return $this->ai_urgency !== null
            && $this->last_message_at !== null
            && ($this->ai_triaged_through === null || $this->last_message_at->greaterThan($this->ai_triaged_through));
    }

    /**
     * Whether the AI was asked to triage the chat and did not answer yet;
     * a request older than AI_TRIAGE_WAIT_MINUTES is given up on, should
     * its job have died without a word.
     */
    public function awaitsAiTriage(): bool
    {
        return $this->ai_triage_requested_at?->greaterThan(now()->subMinutes(self::AI_TRIAGE_WAIT_MINUTES)) ?? false;
    }
}
