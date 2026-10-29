<?php

namespace App\Actions\Shopping;

use App\Models\ShoppingList;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The input of an item, for AddShoppingListItem and UpdateShoppingListItem
 * (US-8.1): `name` (required, up to 255 characters) and `quantity_note`
 * (optional, up to 255), both trimmed; a blank note is stored as null.
 *
 * The items of an archived list are read only: it is unarchived first.
 */
trait SavesShoppingListItem
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, quantity_note: string|null}
     *
     * @throws ValidationException
     */
    private function validatedItem(array $input): array
    {
        foreach (['name', 'quantity_note'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = mb_trim($input[$field]);
            }
        }

        /** @var array{name: string, quantity_note?: string|null} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'quantity_note' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'name' => __('name'),
            'quantity_note' => __('quantity or note'),
        ])->validate();

        return [
            'name' => $validated['name'],
            'quantity_note' => filled($validated['quantity_note'] ?? null) ? $validated['quantity_note'] : null,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function ensureEditable(ShoppingList $list): void
    {
        if ($list->trashed()) {
            throw ValidationException::withMessages(['list' => __('This list was deleted.')]);
        }

        if ($list->isArchived()) {
            throw ValidationException::withMessages(['list' => __('This list is archived. Unarchive it to change its items.')]);
        }
    }
}
