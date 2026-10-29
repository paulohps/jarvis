<?php

namespace App\View\Components\Ui;

use App\Support\Navigation\NavigationItem;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * One entry of the app navigation, as a sidebar row or a tab bar item. The
 * current entry is highlighted and marked `aria-current="page"`; an entry
 * whose route is not registered yet is shown dimmed and without a link.
 *
 * Props:
 * - `item`: the App\Support\Navigation\NavigationItem to show.
 * - `variant`: `sidebar` (icon and label in a row), `sidebar-group` (the
 *   entry of a group, tinted while one of its screens is shown),
 *   `sidebar-child` (a screen of a group, smaller) or `tab` (icon above a
 *   small label).
 *
 * Slots: none.
 */
class NavigationLink extends Component
{
    public const array VARIANTS = ['sidebar', 'sidebar-group', 'sidebar-child', 'tab'];

    public function __construct(
        public NavigationItem $item,
        public string $variant = 'sidebar',
    ) {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new InvalidArgumentException("The navigation variant [{$variant}] is not one of: ".implode(', ', self::VARIANTS).'.');
        }
    }

    public function render(): View
    {
        return view('components.ui.navigation-link');
    }
}
