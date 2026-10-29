<?php

namespace App\Models;

use App\Observers\NotificationSettingsObserver;
use Database\Factories\NotificationTypeChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A channel a notification type is delivered through, with its default for
 * users who never set a preference, its templates per language and, for
 * e-mail, the service it goes out through.
 *
 * @property int $id
 * @property int $notification_type_id
 * @property int $notification_channel_id
 * @property bool $is_active
 * @property bool $is_enabled_by_default
 * @property int|null $mail_service_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NotificationType $type
 * @property-read NotificationChannel $channel
 * @property-read MailService|null $mailService
 */
#[Fillable(['notification_type_id', 'notification_channel_id', 'is_active', 'is_enabled_by_default', 'mail_service_id'])]
#[ObservedBy([NotificationSettingsObserver::class])]
class NotificationTypeChannel extends Model
{
    /** @use HasFactory<NotificationTypeChannelFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_enabled_by_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<NotificationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /**
     * The global service the e-mail channel sends through; null for the
     * default mailer.
     *
     * @return BelongsTo<MailService, $this>
     */
    public function mailService(): BelongsTo
    {
        return $this->belongsTo(MailService::class);
    }

    /**
     * @return HasMany<NotificationTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }
}
