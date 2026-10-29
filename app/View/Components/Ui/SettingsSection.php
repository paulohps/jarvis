<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * A block of a settings screen (profile, admin): a heading, what the block
 * changes and its fields on an opaque card.
 *
 * Props:
 * - `title`: the heading.
 * - `description`: what the block changes.
 *
 * Slots:
 * - default: the fields.
 * - `status`: content beside the heading (a badge).
 * - `actions`: buttons under the fields, right aligned.
 */
class SettingsSection extends Component
{
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}

    public function headingId(): string
    {
        return 'settings-'.Str::slug($this->title);
    }

    public function render(): View
    {
        return view('components.ui.settings-section');
    }
}
