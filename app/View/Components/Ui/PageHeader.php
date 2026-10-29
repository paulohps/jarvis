<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The iOS large title of a screen: its only `<h1>`, an optional subtitle and
 * the screen's actions, which wrap under the title on narrow screens.
 *
 * Props:
 * - `title`: the screen name.
 * - `subtitle`: a line under the title.
 *
 * Slots:
 * - `actions`: buttons beside (or under) the title.
 * - `leading`: content above the title, such as a back link.
 */
class PageHeader extends Component
{
    public function __construct(
        public string $title,
        public ?string $subtitle = null,
    ) {}

    public function render(): View
    {
        return view('components.ui.page-header');
    }
}
