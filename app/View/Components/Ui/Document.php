<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The HTML document every layout (`app-shell`, `guest`) is built on: language,
 * the system color scheme, the assets and TallStackUI's global interactions
 * (`<x-toast>` and `<x-dialog>`, driven from Livewire).
 *
 * Props:
 * - `title`: the screen name, written before the app name in the tab title.
 *
 * Slots:
 * - default: the body of the page.
 */
class Document extends Component
{
    public function __construct(
        public ?string $title = null,
    ) {}

    /**
     * The `<title>` of the page: "Screen · Jarvis", or the app name alone.
     */
    public function documentTitle(): string
    {
        return filled($this->title) ? $this->title.' · '.config('app.name') : (string) config('app.name');
    }

    public function render(): View
    {
        return view('components.ui.document');
    }
}
