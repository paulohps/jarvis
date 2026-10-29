<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use App\Observers\WhatsappAccountObserver;
use Database\Factories\WhatsappAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp number a user connected through the Evolution API. Owned by
 * one user and never shared (US-7.1). The connection state comes from the
 * Evolution API webhooks. An account of a super admin may be the one the
 * platform sends WhatsApp notifications from.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property string $nickname
 * @property string $instance_name
 * @property string|null $phone_number
 * @property int $whatsapp_connection_status_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $chats_synced_at
 * @property string|null $proxy_protocol
 * @property string|null $proxy_host
 * @property int|null $proxy_port
 * @property string|null $proxy_username
 * @property string|null $proxy_password
 * @property bool $sends_read_receipts whether opening a chat tells WhatsApp it was read
 * @property bool $sends_typing whether typing on the chat screen shows "typing…" on WhatsApp
 * @property bool $notifies_messages whether what its chats receive reaches the apps by push
 * @property bool $syncs_phone_reads whether a chat read on the phone is read in Jarvis too
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WhatsappConnectionStatus $status
 */
#[Fillable([
    'user_id', 'nickname', 'instance_name', 'phone_number', 'whatsapp_connection_status_id',
    'connected_at', 'disconnected_at', 'last_synced_at', 'chats_synced_at',
    'proxy_protocol', 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password',
    'sends_read_receipts', 'sends_typing', 'notifies_messages', 'syncs_phone_reads',
])]
#[Hidden(['proxy_password'])]
#[ObservedBy([WhatsappAccountObserver::class])]
class WhatsappAccount extends Model
{
    /** @use HasFactory<WhatsappAccountFactory> */
    use HasFactory, HasFriendlyId;

    protected $table = 'whatsapp_accounts';

    public static function idPrefix(): string
    {
        return 'wacc';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'chats_synced_at' => 'datetime',
            'proxy_port' => 'integer',
            'proxy_password' => 'encrypted',
            'sends_read_receipts' => 'boolean',
            'sends_typing' => 'boolean',
            'notifies_messages' => 'boolean',
            'syncs_phone_reads' => 'boolean',
        ];
    }

    /**
     * The protocols a proxy may speak (the Evolution API's).
     *
     * @var list<string>
     */
    public const array PROXY_PROTOCOLS = ['http', 'https', 'socks4', 'socks5'];

    /**
     * Whether the connection goes out through a proxy.
     */
    public function hasProxy(): bool
    {
        return $this->proxy_host !== null;
    }

    /**
     * The proxy as the Evolution API takes it, or null for none.
     *
     * @return array{protocol: string, host: string, port: int, username: string|null, password: string|null}|null
     */
    public function proxy(): ?array
    {
        if (! $this->hasProxy()) {
            return null;
        }

        return [
            'protocol' => (string) $this->proxy_protocol,
            'host' => (string) $this->proxy_host,
            'port' => (int) $this->proxy_port,
            'username' => $this->proxy_username,
            'password' => $this->proxy_password,
        ];
    }

    /**
     * Whether the account is connected and can send.
     */
    public function isOpen(): bool
    {
        return $this->whatsapp_connection_status_id === WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::OPEN);
    }

    /**
     * The accounts the platform may send WhatsApp notifications from: those
     * of a super admin, who owns that channel.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForNotifications(Builder $query): void
    {
        $query->whereRelation('user', 'is_super_admin', true);
    }

    /**
     * Whether the platform may still send WhatsApp notifications from it:
     * its owner is still a super admin.
     */
    public function isUsableForNotifications(): bool
    {
        return $this->user->isSuperAdmin();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WhatsappConnectionStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnectionStatus::class, 'whatsapp_connection_status_id');
    }

    /**
     * The synced contacts and groups, and the numbers typed manually.
     *
     * @return HasMany<WhatsappTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(WhatsappTarget::class);
    }

    /**
     * @return HasMany<WhatsappMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    /**
     * @return HasMany<WhatsappDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WhatsappDelivery::class);
    }
}
