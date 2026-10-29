<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * The currencies an invoice is issued in (US-6.3). `slug` is the ISO 4217
 * code; `name` is the English long name the invoice prints ("American
 * dollar"), translated by `labelFor()`.
 *
 * @property string $symbol
 * @property int $decimal_places
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'symbol', 'decimal_places', 'is_active'])]
class Currency extends Lookup
{
    public const string USD = 'USD';

    public const string EUR = 'EUR';

    public const string BRL = 'BRL';

    /**
     * The long name of a currency in `$locale` (the current language by
     * default); an unknown code is returned as is.
     */
    public static function labelFor(string $slug, ?string $locale = null): string
    {
        return match ($slug) {
            self::USD => __('American dollar', locale: $locale),
            self::EUR => __('Euro', locale: $locale),
            self::BRL => __('Brazilian real', locale: $locale),
            default => $slug,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The long name of this currency in `$locale` (the current language by
     * default).
     */
    public function label(?string $locale = null): string
    {
        return static::labelFor($this->slug, $locale);
    }
}
