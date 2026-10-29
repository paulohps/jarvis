<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An e-mail invitation to join a workspace (US-1.1). Only the sha256 of
 * the link token is stored. Its status is derived from the timestamps, never
 * stored: accepted and revoked invitations stay as history.
 *
 * @property int $id
 * @property string $friendly_id
 * @property string $email
 * @property string $token_hash
 * @property int $locale_id
 * @property int|null $workspace_id null only for invitations made before workspaces
 * @property int $invited_by_id
 * @property int|null $accepted_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $status
 * @property-read Locale $locale
 * @property-read Workspace|null $workspace
 * @property-read User $invitedBy
 * @property-read User|null $acceptedUser
 */
#[Fillable(['email', 'token_hash', 'locale_id', 'workspace_id', 'invited_by_id', 'expires_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasFriendlyId;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_REVOKED = 'revoked';

    /**
     * How long an invitation stays valid (US-1.1).
     */
    public const int VALIDITY_DAYS = 7;

    public static function idPrefix(): string
    {
        return 'inv';
    }

    /**
     * What `token_hash` stores for a link token.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A new random link token. Only its hash is stored.
     */
    public static function newToken(): string
    {
        return Str::random(64);
    }

    /**
     * The invitation the link token belongs to, whatever its status.
     */
    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * The page that accepts the invitation.
     */
    public static function acceptUrl(string $token): string
    {
        return route('invitations.accept', ['token' => $token]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * `accepted` and `revoked` are final; an open invitation past
     * `expires_at` is `expired`, otherwise `pending`.
     *
     * @return Attribute<string, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->accepted_at !== null) {
                return self::STATUS_ACCEPTED;
            }

            if ($this->revoked_at !== null) {
                return self::STATUS_REVOKED;
            }

            return $this->expires_at->isPast() ? self::STATUS_EXPIRED : self::STATUS_PENDING;
        });
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Invitations that can still be accepted: neither accepted nor revoked,
     * and not past `expires_at`.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForEmail(Builder $query, string $email): void
    {
        $query->where('email', Str::lower(trim($email)));
    }

    /**
     * The workspace the user who accepts joins.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The modules switched on, in the workspace, for the user who accepts.
     *
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class)->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /**
     * The language of the invitation e-mail and of the account created.
     *
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
