<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What a screen or list shows before it has anything, with the action that
 * fills it.
 *
 * Props:
 * - `title`: what is missing.
 * - `description`: what to do about it.
 * - `icon`: a Heroicon (default `inbox`).
 *
 * Slots:
 * - `actions`: buttons under the text.
 */
class EmptyState extends Component
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public string $icon = 'inbox',
    ) {}

    public function render(): View
    {
        return view('components.ui.empty-state');
    }
}
