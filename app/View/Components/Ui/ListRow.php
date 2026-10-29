<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A row of `<x-ui.inset-grouped-list>`: an optional icon tile, a title with
 * an optional subtitle and trailing content. With `href` the whole row is a
 * link and shows a disclosure chevron.
 *
 * Props:
 * - `title`: the main text.
 * - `subtitle`: a secondary line.
 * - `icon`: a Heroicon shown in a tinted tile.
 * - `href`: makes the row a link.
 * - `chevron`: shows the chevron (defaults to true for links).
 *
 * Slots:
 * - default: extra content under the title.
 * - `leading`: replaces the icon tile (avatar, checkbox).
 * - `trailing`: content at the end (value, badge, toggle).
 */
class ListRow extends Component
{
    public bool $chevron;

    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $icon = null,
        public ?string $href = null,
        ?bool $chevron = null,
    ) {
        $this->chevron = $chevron ?? $href !== null;
    }

    public function render(): View
    {
        return view('components.ui.list-row');
    }
}
