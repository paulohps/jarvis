<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Continue with Google" under the forms of the sign-in and invitation
 * screens (US-1.3), after an "or" divider.
 *
 * Props:
 * - `href`: where the button goes (the Google redirect route).
 *
 * Slots: none.
 */
class GoogleButton extends Component
{
    public function __construct(
        public string $href,
    ) {}

    public function render(): View
    {
        return view('components.ui.google-button');
    }
}
