<?php

namespace App\Actions\Invoices;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The fields of a payer, shared by `CreatePayer` and `UpdatePayer`.
 */
final class PayerRules
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, address: string}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $input = InvoiceInput::trim($input, ['name', 'address']);

        /** @var array{name: string, address: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:2000'],
        ], attributes: [
            'name' => __('name'),
            'address' => __('address'),
        ])->validate();

        return $validated;
    }
}
