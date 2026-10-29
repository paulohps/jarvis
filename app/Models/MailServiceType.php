<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How a mail service sends: an SMTP server, a Google account through the
 * Gmail API, or Resend's HTTP API.
 *
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'is_active'])]
class MailServiceType extends Lookup
{
    public const string SMTP = 'smtp';

    public const string GMAIL = 'gmail';

    public const string RESEND = 'resend';

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
     * @return HasMany<MailService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(MailService::class);
    }
}
