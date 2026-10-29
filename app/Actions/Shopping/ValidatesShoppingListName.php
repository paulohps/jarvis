<?php

namespace App\Actions\Shopping;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The name of a shopping list (US-8.1): required, up to 100 characters,
 * trimmed.
 */
trait ValidatesShoppingListName
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function validatedListName(array $input): string
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = mb_trim($input['name']);
        }

        /** @var array{name: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
        ], attributes: [
            'name' => __('name'),
        ])->validate();

        return $validated['name'];
    }
}
