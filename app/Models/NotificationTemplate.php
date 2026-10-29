<?php

namespace App\Models;

use App\Observers\NotificationSettingsObserver;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The text of a notification type on one channel in one language, edited by
 * the super admin. `title` is the mail subject / push title and `body` is
 * sanitized HTML, both `{% %}` templates; `actions` point to links the type
 * class defines. Only the last editor is recorded.
 *
 * @property int $id
 * @property int $notification_type_channel_id
 * @property int $locale_id
 * @property string $title
 * @property string $body
 * @property list<array{link_key: string, label: string}>|null $actions
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NotificationTypeChannel $typeChannel
 * @property-read Locale $locale
 * @property-read User|null $updatedBy
 */
#[Fillable(['notification_type_channel_id', 'locale_id', 'title', 'body', 'actions', 'updated_by_id'])]
#[ObservedBy([NotificationSettingsObserver::class])]
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<NotificationTypeChannel, $this>
     */
    public function typeChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationTypeChannel::class, 'notification_type_channel_id');
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
