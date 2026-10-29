<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * A notification stored by the database channel (the in-app bell),
 * extended for the notification center: `notification_type_id` ties it to a
 * center type (null = sent outside the center). `data` holds the title and
 * body already rendered in the recipient's language.
 *
 * @property string $id
 * @property string $type
 * @property int|null $notification_type_id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NotificationType|null $notificationType
 */
class Notification extends DatabaseNotification
{
    /**
     * @return BelongsTo<NotificationType, $this>
     */
    public function notificationType(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class);
    }
}
