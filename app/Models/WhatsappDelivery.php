<?php

namespace App\Models;

use Database\Factories\WhatsappDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message sent to one target: the single log of the WhatsApp service,
 * whatever sent it (scheduled, manual, notification or voice). A scheduled
 * delivery is unique per message, target and `scheduled_for` slot.
 *
 * @property int $id
 * @property int $whatsapp_account_id
 * @property int $whatsapp_target_id
 * @property int|null $whatsapp_message_id
 * @property int $whatsapp_delivery_source_id
 * @property int $delivery_status_id
 * @property string $body
 * @property string|null $media_path
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property array{quoted?: array{key: array<string, mixed>, message?: array<string, mixed>, sender_jid: string|null, type: string|null, body: string|null}, mentioned?: list<string>, poll?: array{name: string, options: list<string>, selectable_count: int}, location?: array{latitude: float, longitude: float, name: string|null, address: string|null}, contacts?: list<array{name: string, number: string}>, sticker?: bool}|null $payload what it sends beyond a text or a file
 * @property Carbon|null $scheduled_for
 * @property int $attempts
 * @property string|null $external_message_id
 * @property string|null $error_message
 * @property int|null $triggered_by_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappAccount $account
 * @property-read WhatsappTarget $target
 * @property-read WhatsappMessage|null $message
 * @property-read WhatsappDeliverySource $source
 * @property-read DeliveryStatus $status
 * @property-read User|null $triggeredBy
 */
#[Fillable([
    'whatsapp_account_id', 'whatsapp_target_id', 'whatsapp_message_id', 'whatsapp_delivery_source_id',
    'delivery_status_id', 'body', 'media_path', 'media_mime', 'media_name', 'payload', 'scheduled_for', 'attempts', 'external_message_id', 'error_message',
    'triggered_by_id', 'sent_at',
])]
class WhatsappDelivery extends Model
{
    /** @use HasFactory<WhatsappDeliveryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WhatsappAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    /**
     * @return BelongsTo<WhatsappTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(WhatsappTarget::class, 'whatsapp_target_id');
    }

    /**
     * Null for send-now, notification and voice deliveries. Includes a
     * soft-deleted message, so the history keeps its origin.
     *
     * @return BelongsTo<WhatsappMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessage::class, 'whatsapp_message_id')->withTrashed();
    }

    /**
     * @return BelongsTo<WhatsappDeliverySource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(WhatsappDeliverySource::class, 'whatsapp_delivery_source_id');
    }

    /**
     * @return BelongsTo<DeliveryStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(DeliveryStatus::class, 'delivery_status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }
}
