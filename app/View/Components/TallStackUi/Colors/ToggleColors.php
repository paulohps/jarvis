<?php

namespace App\View\Components\TallStackUi\Colors;

use Illuminate\View\Component;

/**
 * The "on" track of TallStackUI toggles: the iOS switch green for the default
 * color, read by TallStackUI from `tallstackui.color_classes_namespace`.
 * A null color keeps the package default.
 */
class ToggleColors
{
    /**
     * Background colors.
     *
     * @return array<string, string|null>
     */
    public function backgroundColors(Component $component): array
    {
        return [
            'primary' => 'peer-checked:bg-switch-on',
            'red' => 'peer-checked:bg-danger',
        ];
    }
}
