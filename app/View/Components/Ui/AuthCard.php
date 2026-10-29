<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The centered glass card of the visitor screens (sign in, invitation,
 * password reset): the screen's only `<h1>` and its form.
 *
 * Props:
 * - `title`: the heading.
 * - `description`: a line under the heading.
 *
 * Slots:
 * - default: the form or message.
 * - `footer`: links under the card.
 */
class AuthCard extends Component
{
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}

    public function render(): View
    {
        return view('components.ui.auth-card');
    }
}
