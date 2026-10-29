<?php

namespace App\View\Components\Ui;

use App\Services\Templates\TemplatePreview as RenderedPreview;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The preview of a template (US-4.2), from the PreviewTemplate action: the
 * sanitized HTML of a body, the plain text of a title or WhatsApp message
 * (line breaks kept), or, for an invalid template, its problems instead.
 *
 * Props:
 * - `preview`: the TemplatePreview to show.
 * - `label`: the heading (default "Preview").
 *
 * Slots: none.
 */
class TemplatePreview extends Component
{
    public string $label;

    public function __construct(
        public RenderedPreview $preview,
        ?string $label = null,
    ) {
        $this->label = $label ?? __('Preview');
    }

    public function render(): View
    {
        return view('components.ui.template-preview');
    }
}
