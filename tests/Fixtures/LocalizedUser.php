<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * A user with a saved language, standing in until `users.locale_id` lands
 * (Phase 2): SetLocale only depends on HasLocalePreference.
 */
class LocalizedUser extends User implements HasLocalePreference
{
    protected $table = 'users';

    public ?string $savedLocale = null;

    public function preferredLocale(): ?string
    {
        return $this->savedLocale;
    }
}
