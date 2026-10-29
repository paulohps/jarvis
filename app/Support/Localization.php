<?php

namespace App\Support;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;

/**
 * Single source of truth for the interface languages (US-12.2): English is
 * the default and pt_BR the full translation.
 *
 * Resolves the language of a request from the signed-in user's preference,
 * then the language a web visitor picked (kept in the session), or, for a
 * guest of the API, the `Accept-Language` header.
 */
final class Localization
{
    public const string DEFAULT_LOCALE = 'en';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_LOCALES = ['en', 'pt_BR'];

    /**
     * Each language written in itself, for the language switch.
     *
     * @var array<string, string>
     */
    public const array NAMES = ['en' => 'English', 'pt_BR' => 'Português (Brasil)'];

    /**
     * The session key holding the language a web visitor picked.
     */
    public const string SESSION_KEY = 'locale';

    public static function isSupported(?string $locale): bool
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    /**
     * The language a request is answered in: the user's saved preference,
     * otherwise the visitor's pick on the web, otherwise the `Accept-Language`
     * header on the API, otherwise English.
     */
    public static function forRequest(Request $request, mixed $user): string
    {
        if ($user instanceof HasLocalePreference) {
            $preferred = self::normalize($user->preferredLocale());

            if ($preferred !== null) {
                return $preferred;
            }
        }

        if (! $request->is('api/*') && $request->hasSession()) {
            $picked = $request->session()->get(self::SESSION_KEY);

            if (self::isSupported($picked)) {
                return $picked;
            }
        }

        if ($request->is('api/*')) {
            return self::fromAcceptLanguage($request) ?? self::DEFAULT_LOCALE;
        }

        return self::DEFAULT_LOCALE;
    }

    /**
     * The first supported language of the `Accept-Language` header, in the
     * client's order of preference. A bare language (`pt`) or another region
     * of it (`pt-PT`) falls back to the supported locale of that language.
     */
    public static function fromAcceptLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $locale = self::normalize($language);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Maps a locale written as `pt-BR`, `pt_br` or `pt` onto a supported one.
     */
    public static function normalize(?string $locale): ?string
    {
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        $locale = str_replace('-', '_', $locale);

        foreach (self::SUPPORTED_LOCALES as $supported) {
            if (strcasecmp($supported, $locale) === 0) {
                return $supported;
            }
        }

        $language = strtolower(explode('_', $locale)[0]);

        foreach (self::SUPPORTED_LOCALES as $supported) {
            if (strtolower(explode('_', $supported)[0]) === $language) {
                return $supported;
            }
        }

        return null;
    }
}
