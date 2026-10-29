<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The iOS segmented control: mutually exclusive options in a pill, built on
 * native radio buttons, so it works with the keyboard, in plain forms and
 * with `wire:model` (forwarded to every radio).
 *
 * Props:
 * - `name`: the field name shared by the radios.
 * - `options`: value => label pairs, in display order.
 * - `value`: the selected value without `wire:model`.
 * - `label`: the accessible name of the group.
 *
 * Slots: none.
 */
class SegmentedControl extends Component
{
    /**
     * @param  array<string|int, string>  $options
     */
    public function __construct(
        public string $name,
        public array $options,
        public string|int|null $value = null,
        public ?string $label = null,
    ) {}

    public function isSelected(string|int $option): bool
    {
        return $this->value !== null && (string) $this->value === (string) $option;
    }

    public function render(): View
    {
        return view('components.ui.segmented-control');
    }
}
