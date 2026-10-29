<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\MailServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service e-mail goes out through: an SMTP server, a Google account
 * (Gmail API, connected with OAuth) or Resend. It belongs to whoever created
 * it; the super admin may mark it global, which offers it to everyone. The
 * notification e-mail may go out through a global service or through any
 * service of a super admin.
 *
 * `secret` holds the SMTP password, the Resend API key or the Google
 * refresh token. It is encrypted at rest and never serialized;
 * `secret_last_four` is what the interface shows (not for Google).
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $mail_service_type_id
 * @property int $user_id
 * @property string $name
 * @property string $from_address
 * @property string|null $from_name
 * @property string|null $host
 * @property int|null $port
 * @property string|null $encryption
 * @property string|null $username
 * @property string|null $secret
 * @property string|null $secret_last_four
 * @property bool $is_global
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MailServiceType $type
 * @property-read User $user
 */
#[Fillable([
    'mail_service_type_id',
    'user_id',
    'name',
    'from_address',
    'from_name',
    'host',
    'port',
    'encryption',
    'username',
    'secret',
    'secret_last_four',
    'is_global',
])]
#[Hidden(['secret'])]
class MailService extends Model
{
    /** @use HasFactory<MailServiceFactory> */
    use HasFactory, HasFriendlyId;

    /**
     * SMTP over TLS from the first byte (usually port 465).
     */
    public const string ENCRYPTION_SSL = 'ssl';

    /**
     * SMTP that upgrades to TLS with STARTTLS (usually port 587).
     */
    public const string ENCRYPTION_TLS = 'tls';

    public static function idPrefix(): string
    {
        return 'mls';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'secret' => 'encrypted',
            'is_global' => 'boolean',
        ];
    }

    /**
     * The services the user may send through: their own and the global ones.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsableBy(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where($this->qualifyColumn('user_id'), $user->id)
                ->orWhere($this->qualifyColumn('is_global'), true);
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_global'), true);
    }

    /**
     * The services the platform's notification e-mail may go out through:
     * the global ones and those of a super admin, who owns that e-mail.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForNotifications(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where($this->qualifyColumn('is_global'), true)
                ->orWhereRelation('user', 'is_super_admin', true);
        });
    }

    public function isUsableBy(User $user): bool
    {
        return $this->is_global || $this->user_id === $user->id;
    }

    /**
     * Whether the notification e-mail may still go out through it: global,
     * or its owner is still a super admin.
     */
    public function isUsableForNotifications(): bool
    {
        return $this->is_global || $this->user->isSuperAdmin();
    }

    public function isOfType(string $slug): bool
    {
        return $this->mail_service_type_id === MailServiceType::idFor($slug);
    }

    /**
     * The secret as the interface shows it: only its last four characters.
     */
    public function maskedSecret(): ?string
    {
        return $this->secret_last_four === null ? null : '••••'.$this->secret_last_four;
    }

    /**
     * The sender as the list shows it: "Name <address>".
     */
    public function sender(): string
    {
        return filled($this->from_name) ? "{$this->from_name} <{$this->from_address}>" : $this->from_address;
    }

    /**
     * @return BelongsTo<MailServiceType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(MailServiceType::class, 'mail_service_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
