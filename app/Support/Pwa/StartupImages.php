<?php

namespace App\Support\Pwa;

/**
 * The launch screens iOS shows while the installed web app opens
 * (`apple-touch-startup-image`): one PNG per screen size, picked by a media
 * query on the device's CSS size, pixel ratio and orientation. The PNGs
 * are rendered by scripts/generate-brand-assets.py from
 * resources/brand/splash.svg, which keeps the same list.
 */
final class StartupImages
{
    /**
     * The screens, as CSS width × height (points) and pixel ratio:
     * the iPhones since the X and the iPads still sold.
     *
     * @var list<array{0: int, 1: int, 2: int}>
     */
    private const array SCREENS = [
        [440, 956, 3], // iPhone 16 Pro Max, 17 Pro Max
        [430, 932, 3], // iPhone 14 Pro Max, 15 Plus, 15 Pro Max, 16 Plus
        [428, 926, 3], // iPhone 12 Pro Max, 13 Pro Max, 14 Plus
        [414, 896, 3], // iPhone XS Max, 11 Pro Max
        [414, 896, 2], // iPhone XR, 11
        [402, 874, 3], // iPhone 16 Pro, 17, 17 Pro
        [393, 852, 3], // iPhone 14 Pro, 15, 15 Pro, 16, 16e
        [390, 844, 3], // iPhone 12, 12 Pro, 13, 13 Pro, 14
        [375, 812, 3], // iPhone X, XS, 11 Pro, 12 mini, 13 mini
        [375, 667, 2], // iPhone SE (2nd and 3rd generation), 8
        [1024, 1366, 2], // iPad Pro 12.9" and 13"
        [834, 1210, 2], // iPad Pro 11" (M4)
        [834, 1194, 2], // iPad Pro 11" (up to M2)
        [820, 1180, 2], // iPad Air, iPad (10th generation)
        [810, 1080, 2], // iPad (7th to 9th generation)
        [768, 1024, 2], // iPad mini (5th generation) and older iPads
        [744, 1133, 2], // iPad mini (6th generation and A17 Pro)
    ];

    /**
     * Every launch screen link: the file and the media query that picks it,
     * portrait and landscape.
     *
     * @return list<array{href: string, media: string}>
     */
    public static function links(): array
    {
        $links = [];

        foreach (self::SCREENS as [$width, $height, $ratio]) {
            foreach (['portrait', 'landscape'] as $orientation) {
                $links[] = [
                    'href' => '/splash/'.self::fileName($width, $height, $ratio, $orientation),
                    'media' => "screen and (device-width: {$width}px) and (device-height: {$height}px) and (-webkit-device-pixel-ratio: {$ratio}) and (orientation: {$orientation})",
                ];
            }
        }

        return $links;
    }

    /**
     * The files the generator renders: name => [pixel width, pixel height].
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::SCREENS as [$width, $height, $ratio]) {
            $files[self::fileName($width, $height, $ratio, 'portrait')] = [$width * $ratio, $height * $ratio];
            $files[self::fileName($width, $height, $ratio, 'landscape')] = [$height * $ratio, $width * $ratio];
        }

        return $files;
    }

    private static function fileName(int $width, int $height, int $ratio, string $orientation): string
    {
        [$pixelWidth, $pixelHeight] = $orientation === 'portrait'
            ? [$width * $ratio, $height * $ratio]
            : [$height * $ratio, $width * $ratio];

        return "launch-{$pixelWidth}x{$pixelHeight}.png";
    }
}
