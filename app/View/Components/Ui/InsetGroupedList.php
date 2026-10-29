<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * An iOS inset grouped list: rounded opaque cells separated by hairlines on
 * the grouped background, with an optional small caption above and below.
 *
 * Props:
 * - `header`: the caption above the group.
 * - `footer`: the note under the group.
 * - `sort`: a Livewire method that makes the rows sortable by drag and drop
 *   (`wire:sort`); each row then needs `wire:sort:item`.
 *
 * Slots:
 * - default: the rows, usually `<x-ui.list-row>` elements.
 * - `header` / `footer`: rich replacements for the props.
 */
class InsetGroupedList extends Component
{
    public function __construct(
        public ?string $header = null,
        public ?string $footer = null,
        public ?string $sort = null,
    ) {}

    public function render(): View
    {
        return view('components.ui.inset-grouped-list');
    }
}
