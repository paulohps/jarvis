<?php

namespace App\Support;

/**
 * Generates and checks public identifiers in the form `{prefix}_{24 chars}`.
 *
 * The alphabet leaves out visually ambiguous characters (`i`/`l`/`I`/`o`/`O`/
 * `v`/`V`/`7`/`8`/`9`) so an id can be read aloud or retyped. With the
 * `varchar(30)` column of `Blueprint::friendlyId()`, prefixes have at most
 * five characters.
 */
final class FriendlyId
{
    public const string ALPHABET = 'abcdefghjkmnpqrstuwxyz0123456ABCDEFGHJKLMNPQRSTUWXYZ';

    public const int LENGTH = 24;

    public const int MAX_LENGTH = 30;

    /**
     * Bytes at or above this value are discarded: 208 is the largest multiple
     * of the 52-character alphabet that fits in a byte, so `byte % 52` stays
     * uniform instead of favouring the first 48 characters.
     */
    private const int UNBIASED_BYTE_LIMIT = 208;

    public static function generate(string $prefix): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $id = '';

        while (strlen($id) < self::LENGTH) {
            foreach (str_split(random_bytes(self::LENGTH)) as $byte) {
                $value = ord($byte);

                if ($value >= self::UNBIASED_BYTE_LIMIT) {
                    continue;
                }

                $id .= self::ALPHABET[$value % $alphabetLength];

                if (strlen($id) === self::LENGTH) {
                    break;
                }
            }
        }

        return "{$prefix}_{$id}";
    }

    /**
     * Whether the id carries the expected prefix, the exact length and only
     * characters of the alphabet.
     */
    public static function isValid(string $id, string $expectedPrefix): bool
    {
        return preg_match('/\A'.self::pattern($expectedPrefix).'\z/', $id) === 1;
    }

    /**
     * Route constraint for a friendly id of the given prefix (no anchors or
     * delimiters, as `Route::where()` expects).
     */
    public static function pattern(string $prefix): string
    {
        return preg_quote($prefix, '/').'_['.self::ALPHABET.']{'.self::LENGTH.'}';
    }
}
