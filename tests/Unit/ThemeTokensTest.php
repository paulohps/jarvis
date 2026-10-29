<?php

use App\Support\ThemeColors;

/**
 * The color tokens of resources/css/app.css: the light theme (`@theme`) and
 * the dark one (`prefers-color-scheme: dark`).
 *
 * @return array{light: array<string, string>, dark: array<string, string>}
 */
function themeTokens(): array
{
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $light);
    preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root\s*\{(.*?)\}/s', $css, $dark);

    $parse = function (string $block): array {
        preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6})\s*;/i', $block, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => strtolower($match[2])])->all();
    };

    return ['light' => $parse($light[1] ?? ''), 'dark' => $parse($dark[1] ?? '')];
}

function contrastRatio(string $foreground, string $background): float
{
    $luminance = function (string $hex): float {
        $channels = array_map(
            function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };

    $lighter = max($luminance($foreground), $luminance($background));
    $darker = min($luminance($foreground), $luminance($background));

    return ($lighter + 0.05) / ($darker + 0.05);
}

test('both themes declare the same semantic color tokens', function () {
    $tokens = themeTokens();

    // `gray-*` and `dark-*` are TallStackUI's fixed palettes, one per theme.
    $semantic = array_values(array_filter(
        array_keys($tokens['light']),
        fn (string $name): bool => ! preg_match('/^(gray|dark)-\d+$/', $name),
    ));

    expect($semantic)->not->toBeEmpty()
        ->and(array_keys($tokens['dark']))->toEqualCanonicalizing($semantic);
});

test('text tokens keep a contrast of at least 4.5 to 1 on every background', function (string $theme) {
    $tokens = themeTokens()[$theme];

    $texts = ['label', 'secondary-label', 'tint', 'danger', 'success', 'warning'];
    $backgrounds = ['system-background', 'secondary-system-background', 'grouped-background', 'secondary-grouped-background', 'tertiary-grouped-background'];

    foreach ($texts as $text) {
        foreach ($backgrounds as $background) {
            expect(contrastRatio($tokens[$text], $tokens[$background]))
                ->toBeGreaterThanOrEqual(4.5, "{$theme}: {$text} on {$background}");
        }
    }

    foreach (['tint-fill', 'tint-fill-pressed'] as $fill) {
        expect(contrastRatio($tokens['on-tint'], $tokens[$fill]))
            ->toBeGreaterThanOrEqual(4.5, "{$theme}: on-tint on {$fill}");
    }
})->with(['light', 'dark']);

test('the chat keeps its text, time and links readable on both bubbles and the wallpaper', function (string $theme) {
    $tokens = themeTokens()[$theme];

    foreach (['chat-text', 'chat-meta', 'chat-link'] as $text) {
        foreach (['chat-bubble-in', 'chat-bubble-out', 'chat-wallpaper', 'chat-panel'] as $background) {
            expect(contrastRatio($tokens[$text], $tokens[$background]))
                ->toBeGreaterThanOrEqual(4.5, "{$theme}: {$text} on {$background}");
        }
    }

    expect(contrastRatio($tokens['on-tint'], $tokens['chat-accent']))->toBeGreaterThanOrEqual(4.5, "{$theme}: on-tint on chat-accent");
})->with(['light', 'dark']);

test('the chat list keeps its names, previews and unread counts readable on every row state', function (string $theme) {
    $tokens = themeTokens()[$theme];

    foreach (['chat-text', 'chat-meta', 'chat-unread'] as $text) {
        foreach (['chat-list', 'chat-list-hover', 'chat-list-active'] as $background) {
            expect(contrastRatio($tokens[$text], $tokens[$background]))
                ->toBeGreaterThanOrEqual(4.5, "{$theme}: {$text} on {$background}");
        }
    }

    expect(contrastRatio($tokens['on-chat-unread'], $tokens['chat-unread']))->toBeGreaterThanOrEqual(4.5, "{$theme}: on-chat-unread on chat-unread");
})->with(['light', 'dark']);

test('dark mode follows the system color scheme', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    expect($css)->toContain('@media (prefers-color-scheme: dark)')
        ->and($css)->toContain('@custom-variant dark (@media (prefers-color-scheme: dark));');
});

test('the colors written out as literals match the tokens', function () {
    $tokens = themeTokens();

    expect(ThemeColors::GROUPED_BACKGROUND_LIGHT)->toBe($tokens['light']['grouped-background'])
        ->and(ThemeColors::GROUPED_BACKGROUND_DARK)->toBe($tokens['dark']['grouped-background'])
        ->and(ThemeColors::LABEL_LIGHT)->toBe($tokens['light']['label'])
        ->and(ThemeColors::LABEL_DARK)->toBe($tokens['dark']['label'])
        ->and(ThemeColors::SECONDARY_LABEL_LIGHT)->toBe($tokens['light']['secondary-label'])
        ->and(ThemeColors::SECONDARY_LABEL_DARK)->toBe($tokens['dark']['secondary-label'])
        ->and(ThemeColors::TINT_FILL_LIGHT)->toBe($tokens['light']['tint-fill'])
        ->and(ThemeColors::TINT_FILL_DARK)->toBe($tokens['dark']['tint-fill'])
        ->and(ThemeColors::ON_TINT)->toBe($tokens['light']['on-tint'])
        ->and(ThemeColors::ON_TINT)->toBe($tokens['dark']['on-tint']);
});

test('views use no color outside the tokens', function () {
    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|white|black|dark';
    $utility = 'bg|text|border|border-[trblxy]|ring|ring-offset|fill|stroke|from|via|to|divide|outline|shadow|accent|caret|decoration|placeholder';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/views', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        expect($contents)
            ->not->toMatch('/(?<![\w&])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})(?![\w-])/i', "Hex color in {$file->getFilename()}")
            ->not->toMatch('/\b(?:rgba?|hsla?|oklch)\(/i', "Color function in {$file->getFilename()}")
            ->not->toMatch("/(?<![\\w-])(?:{$utility})-(?:{$palette})(?:-\\d{2,3})?\\b/", "Palette color in {$file->getFilename()}");
    }
});

test('the stylesheet writes color literals only in token declarations', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    $offending = collect(explode("\n", $css))
        ->reject(fn (string $line): bool => preg_match('/^\s*(--[a-z0-9-]+:|\/?\*)/', $line) === 1)
        ->filter(fn (string $line): bool => preg_match('/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|oklch)\(/i', $line) === 1)
        ->values()
        ->all();

    expect($offending)->toBe([]);
});
