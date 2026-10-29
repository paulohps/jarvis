<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The iOS search field: a filled pill with a magnifying glass. Every other
 * attribute (`wire:model.live.debounce`, `autofocus`) goes on the input.
 *
 * Props:
 * - `name`: the input name (default `search`).
 * - `placeholder`: the hint (default "Search").
 * - `label`: the accessible name (defaults to the placeholder).
 *
 * Slots: none.
 */
class SearchField extends Component
{
    public string $placeholder;

    public function __construct(
        public string $name = 'search',
        ?string $placeholder = null,
        public ?string $label = null,
    ) {
        $this->placeholder = $placeholder ?? __('Search');
    }

    public function render(): View
    {
        return view('components.ui.search-field');
    }
}
