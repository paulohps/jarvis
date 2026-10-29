<?php

namespace App\Actions\Invoices;

/**
 * Input clean-up shared by the invoices Actions.
 */
final class InvoiceInput
{
    /**
     * Trims the given text fields and turns the line breaks of multiline
     * ones into `\n`, leaving anything that is not a string as it is.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function trim(array $input, array $fields): array
    {
        foreach ($fields as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim(str_replace(["\r\n", "\r"], "\n", $input[$field]));
            }
        }

        return $input;
    }
}
