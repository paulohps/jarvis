<?php

namespace App\Models;

use App\Observers\NotificationSettingsObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The types of the notification center. `slug` is the `key()` of the
 * notification class, which also provides the translated label, so the
 * table has no `name`. Rows are provisioned from the registered classes.
 *
 * @property bool $is_active
 * @property bool $is_visible_in_profile
 */
#[Fillable(['slug', 'is_active', 'is_visible_in_profile'])]
#[ObservedBy([NotificationSettingsObserver::class])]
class NotificationType extends Lookup
{
    public const string INVOICE_SENT = 'invoice_sent';

    public const string INVOICE_FAILED = 'invoice_failed';

    public const string INVOICE_PENDING_REVIEW = 'invoice_pending_review';

    public const string WHATSAPP_ACCOUNT_DISCONNECTED = 'whatsapp_account_disconnected';

    public const string WHATSAPP_MESSAGE_FAILED = 'whatsapp_message_failed';

    public const string WHATSAPP_MESSAGE_RECEIVED = 'whatsapp_message_received';

    public const string REMINDER_DUE = 'reminder_due';

    public const string INVITATION = 'invitation';

    public const string MODULE_ENABLED = 'module_enabled';

    public const string CHAT_MESSAGE_RECEIVED = 'chat_message_received';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible_in_profile' => 'boolean',
        ];
    }

    /**
     * The channels configured for this type.
     *
     * @return HasMany<NotificationTypeChannel, $this>
     */
    public function typeChannels(): HasMany
    {
        return $this->hasMany(NotificationTypeChannel::class);
    }

    /**
     * User preferences about this type.
     *
     * @return HasMany<NotificationPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Notifications sent as this type.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
