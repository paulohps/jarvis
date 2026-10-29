<?php

namespace App\View\Components\Ui;

use App\Support\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The visitor's language switch: a menu with every supported language,
 * each written in itself, the current one checked.
 *
 * Props: none. Slots: none.
 */
class LocaleSwitch extends Component
{
    /**
     * @return array<string, string>
     */
    public function locales(): array
    {
        return Localization::NAMES;
    }

    public function current(): string
    {
        return app()->getLocale();
    }

    public function render(): View
    {
        return view('components.ui.locale-switch');
    }
}
