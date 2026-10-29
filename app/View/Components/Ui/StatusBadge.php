<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * A capsule with the state of a record (invoice, delivery, WhatsApp account,
 * invitation), colored by what the state means.
 *
 * Props:
 * - `status`: the status slug (`sent`, `pending_review`, `failed`...).
 * - `label`: the text; defaults to the translated slug ("Pending review").
 * - `tone`: forces `success`, `warning`, `danger`, `info` or `neutral`.
 *
 * Slots: none.
 */
class StatusBadge extends Component
{
    /**
     * @var array<string, list<string>>
     */
    public const array TONES = [
        'success' => ['sent', 'open', 'done', 'completed', 'accepted', 'active', 'delivered'],
        'warning' => ['pending', 'pending_review', 'connecting', 'scheduled', 'paused', 'queued'],
        'danger' => ['failed', 'closed', 'revoked', 'expired', 'cancelled', 'overdue'],
        'info' => ['processing', 'sending'],
    ];

    public string $tone;

    public string $label;

    public function __construct(
        public string $status,
        ?string $label = null,
        ?string $tone = null,
    ) {
        $this->tone = $tone ?? collect(self::TONES)->search(fn (array $statuses): bool => in_array($status, $statuses, true)) ?: 'neutral';
        $this->label = $label ?? __(Str::ucfirst(str_replace('_', ' ', $status)));
    }

    public function render(): View
    {
        return view('components.ui.status-badge');
    }
}
