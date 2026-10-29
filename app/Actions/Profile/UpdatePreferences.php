<?php

namespace App\Actions\Profile;

use App\Models\Locale;
use App\Models\User;
use App\Support\Localization;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The user picks their interface language and timezone (US-3.2), saved on
 * `users`. The language is `en` or `pt_BR`; the timezone is any PHP
 * identifier (`timezone:all`). Either may be sent alone, as the API's
 * `PATCH /me/preferences` does.
 *
 * `SetLocale` reads the user's language on every request, so the next one
 * (and every notification sent from then on) already uses it.
 */
final readonly class UpdatePreferences
{
    /**
     * @param  array{locale?: mixed, timezone?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, array $input): User
    {
        /** @var array{locale?: string, timezone?: string} $validated */
        $validated = Validator::make($input, [
            'locale' => ['sometimes', 'required', 'string', Rule::in(Localization::SUPPORTED_LOCALES)],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
        ])->validate();

        if (array_key_exists('locale', $validated)) {
            $user->locale_id = Locale::idFor($validated['locale']);
            $user->unsetRelation('locale');
        }

        if (array_key_exists('timezone', $validated)) {
            $user->timezone = $validated['timezone'];
        }

        $user->save();

        return $user;
    }
}
