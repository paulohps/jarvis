<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * An iOS alert asking to confirm an action before it runs. The trigger opens
 * it; Confirm runs the action and closes it, Cancel or Escape just close it.
 * Every `wire:*` and `x-on:*` attribute given to the component goes on the
 * Confirm button (e.g. `wire:click="delete"`).
 *
 * Props:
 * - `title`: the question.
 * - `description`: what happens if confirmed.
 * - `confirmLabel`: the Confirm button text (default "Confirm").
 * - `cancelLabel`: the Cancel button text (default "Cancel").
 * - `danger`: paints Confirm in the destructive color.
 *
 * Slots:
 * - `trigger`: the element that opens the dialog.
 * - default: extra content under the description.
 */
class ConfirmDialog extends Component
{
    public string $confirmLabel;

    public string $cancelLabel;

    public function __construct(
        public string $title,
        public ?string $description = null,
        ?string $confirmLabel = null,
        ?string $cancelLabel = null,
        public bool $danger = false,
    ) {
        $this->confirmLabel = $confirmLabel ?? __('Confirm');
        $this->cancelLabel = $cancelLabel ?? __('Cancel');
    }

    public function render(): View
    {
        return view('components.ui.confirm-dialog');
    }
}
