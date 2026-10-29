<?php

namespace App\Support\Pwa;

use App\Models\Module;
use App\Support\Navigation\AppNavigation;
use App\Support\ThemeColors;
use Illuminate\Support\Facades\Route;

/**
 * The web app manifest (US-12.2, the PWA): what the browser needs to
 * install Jarvis as an app — its names, icons, colors, where it opens and
 * the shortcuts of its home screen icon. Written in the reader's language,
 * so the manifest is fetched with the session (`crossorigin="use-credentials"`).
 *
 * The shortcuts list every module's screen, whoever reads the manifest:
 * one the user has no access to answers like any other page they cannot
 * open.
 */
final class WebManifest
{
    /**
     * The cache name the service worker keys its caches by changes with
     * the built assets, so a deploy replaces what was cached.
     */
    public static function version(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? substr(md5_file($manifest) ?: 'dev', 0, 12) : 'dev';
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $name = (string) config('app.name');

        return [
            'id' => '/',
            'name' => $name,
            'short_name' => $name,
            'description' => __("Your family's daily routines, in one place."),
            'lang' => str_replace('_', '-', app()->getLocale()),
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'any',
            'background_color' => ThemeColors::GROUPED_BACKGROUND_LIGHT,
            'theme_color' => ThemeColors::GROUPED_BACKGROUND_LIGHT,
            'categories' => ['productivity', 'lifestyle'],
            'prefer_related_applications' => false,
            'launch_handler' => ['client_mode' => 'navigate-existing'],
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => self::shortcuts(),
        ];
    }

    /**
     * The screens the home screen icon jumps to: each module, the
     * notifications and the profile.
     *
     * @return list<array{name: string, url: string, icons: list<array{src: string, sizes: string, type: string}>}>
     */
    public static function shortcuts(): array
    {
        $entries = [];

        foreach (Module::query()->active()->orderBy('sort_order')->get() as $module) {
            $item = AppNavigation::moduleItem($module);

            if ($item->exists()) {
                $entries[] = [$item->label, route($item->route, absolute: false)];
            }
        }

        if (Route::has('notifications.index')) {
            $entries[] = [__('Notifications'), route('notifications.index', absolute: false)];
        }

        if (Route::has('profile')) {
            $entries[] = [__('Profile'), route('profile', absolute: false)];
        }

        return array_map(fn (array $entry): array => [
            'name' => $entry[0],
            'url' => $entry[1],
            'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ], $entries);
    }
}
