<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The "Jarvis" mark: an app-icon tile and the name.
 *
 * Props:
 * - `href`: where the mark leads; without it the mark is not a link.
 *
 * Slots: none.
 */
class Brand extends Component
{
    public function __construct(
        public ?string $href = null,
    ) {}

    public function render(): View
    {
        return view('components.ui.brand');
    }
}
