<?php

namespace App\Models;

use App\Observers\NotificationSettingsObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The notification channels. `slug` is the Laravel channel name.
 *
 * The WhatsApp channel may name the account the platform sends WhatsApp
 * notifications from (`whatsapp_account_id`), one of a super admin's; users
 * who did not pick one of their own get them from it.
 *
 * @property bool $is_active
 * @property int|null $whatsapp_account_id
 * @property-read WhatsappAccount|null $whatsappAccount
 */
#[Fillable(['slug', 'name', 'is_active', 'whatsapp_account_id'])]
#[ObservedBy([NotificationSettingsObserver::class])]
class NotificationChannel extends Lookup
{
    public const string DATABASE = 'database';

    public const string MAIL = 'mail';

    public const string PUSH = 'push';

    public const string WHATSAPP = 'whatsapp';

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
     * The account the platform sends WhatsApp notifications from.
     *
     * @return BelongsTo<WhatsappAccount, $this>
     */
    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class);
    }

    /**
     * The notification types delivered through this channel.
     *
     * @return HasMany<NotificationTypeChannel, $this>
     */
    public function typeChannels(): HasMany
    {
        return $this->hasMany(NotificationTypeChannel::class);
    }

    /**
     * User preferences about this channel.
     *
     * @return HasMany<NotificationPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }
}
