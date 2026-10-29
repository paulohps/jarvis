<?php

namespace App\Actions\Invoices;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The fields of an issuer, shared by `CreateIssuer` and `UpdateIssuer`: the
 * "Receiver information" block of the invoice, every field required.
 */
final class IssuerRules
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, postal_code: string, street: string, district: string, city: string, state: string}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $input = InvoiceInput::trim($input, ['name', 'email', 'postal_code', 'street', 'district', 'city', 'state']);

        /** @var array{name: string, email: string, postal_code: string, street: string, district: string, city: string, state: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'street' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:50'],
        ], attributes: [
            'name' => __('name'),
            'email' => __('e-mail'),
            'postal_code' => __('postal code'),
            'street' => __('street and number'),
            'district' => __('district'),
            'city' => __('city'),
            'state' => __('state'),
        ])->validate();

        return $validated;
    }
}
