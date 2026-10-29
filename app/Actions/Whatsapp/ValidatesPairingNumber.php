<?php

namespace App\Actions\Whatsapp;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The number to link by pairing code, as the Evolution API takes it: only
 * the digits, country code included (`+55 (11) 99999-0000` →
 * `5511999990000`), 10 to 15 of them.
 */
trait ValidatesPairingNumber
{
    /**
     * @throws ValidationException
     */
    private function pairingNumber(string $phoneNumber): string
    {
        $digits = (string) preg_replace('/\D/', '', $phoneNumber);

        Validator::make(
            ['phone_number' => $digits],
            ['phone_number' => ['required', 'digits_between:10,15']],
            ['phone_number.digits_between' => __('Type the full number with the country code and area code, like +55 11 99999-0000.')],
        )->validate();

        return $digits;
    }
}
