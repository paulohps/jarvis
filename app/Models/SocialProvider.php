<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Socialite providers a user can sign in with. `slug` is the driver.
 *
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'is_active'])]
class SocialProvider extends Lookup
{
    public const string GOOGLE = 'google';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
