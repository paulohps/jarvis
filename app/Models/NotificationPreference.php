<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's choice to receive, or not, a notification type on a channel. With
 * no row, the type channel's default applies.
 *
 * @property int $id
 * @property int $user_id
 * @property int $notification_type_id
 * @property int $notification_channel_id
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read NotificationType $type
 * @property-read NotificationChannel $channel
 */
#[Fillable(['user_id', 'notification_type_id', 'notification_channel_id', 'is_enabled'])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
}
