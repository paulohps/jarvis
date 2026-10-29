<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The languages of the interface (US-3.2). `slug` is the Laravel locale.
 *
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'is_active'])]
class Locale extends Lookup
{
    public const string EN = 'en';

    public const string PT_BR = 'pt_BR';

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
     * Users who picked this language.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Invitations whose e-mail is written in this language.
     *
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Notification templates written in this language.
     *
     * @return HasMany<NotificationTemplate, $this>
     */
    public function notificationTemplates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }
}
