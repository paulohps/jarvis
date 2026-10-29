<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The body of the error pages (resources/views/errors): code, title, message
 * and the way back, in the guest layout. It never prints the exception
 * message, which may name a model or an id.
 *
 * Props:
 * - `code`: the HTTP status.
 * - `title`: what happened.
 * - `message`: what the reader can do.
 *
 * Slots: none.
 */
class ErrorPage extends Component
{
    public function __construct(
        public string $code,
        public string $title,
        public string $message,
    ) {}

    public function render(): View
    {
        return view('components.ui.error-page');
    }
}
