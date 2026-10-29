<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The first focusable element of every layout: hidden until focused, it jumps
 * over the navigation to `#main-content`.
 *
 * Props: none. Slots: none.
 */
class SkipLink extends Component
{
    public function render(): View
    {
        return view('components.ui.skip-link');
    }
}
