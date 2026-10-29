<?php

namespace App\Support;

/**
 * The color tokens of resources/css/app.css that have to be written out as
 * literals outside the stylesheet, such as the browser's `theme-color`
 * (which takes no CSS variable). Views read them from here, so no hex code
 * lives in a view; a test keeps them equal to the tokens.
 */
final class ThemeColors
{
    /**
     * `--color-grouped-background` of the light theme: the page behind the
     * signed-in screens and the browser bar above it.
     */
    public const string GROUPED_BACKGROUND_LIGHT = '#f2f2f7';

    /**
     * `--color-grouped-background` of the dark theme.
     */
    public const string GROUPED_BACKGROUND_DARK = '#000000';

    /*
     * The tokens the offline page of the PWA paints with, which loads no
     * stylesheet (there is no connection).
     */

    public const string LABEL_LIGHT = '#000000';

    public const string LABEL_DARK = '#ffffff';

    public const string SECONDARY_LABEL_LIGHT = '#636366';

    public const string SECONDARY_LABEL_DARK = '#98989f';

    public const string TINT_FILL_LIGHT = '#0062cc';

    public const string TINT_FILL_DARK = '#0060df';

    public const string ON_TINT = '#ffffff';
}
