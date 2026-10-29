<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Carbon\CarbonInterface;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A device of a user registered for push: a phone, tied to the Sanctum token
 * the app signed in with, or a browser the web app registered (platform
 * `web`, no token); `push_token` is its FCM registration token, removed when
 * FCM rejects it (US-5.2). `last_used_at` is set when the device registers
 * and each time a push reaches it; `lastUsedAt()` is what the profile and
 * the API show, the later of that and the token's last request. Revoking
 * the device revokes the token (US-3.4).
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property int $device_platform_id
 * @property string $name
 * @property string|null $push_token
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read DevicePlatform $platform
 * @property-read PersonalAccessToken|null $personalAccessToken
 */
#[Fillable(['user_id', 'personal_access_token_id', 'device_platform_id', 'name', 'push_token', 'last_used_at'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasFriendlyId;

    public static function idPrefix(): string
    {
        return 'dev';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The most recently used first (as `lastUsedAt()` tells it), the never
     * used last.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function latestUsedFirst(Builder $query): void
    {
        $usedAt = 'greatest(devices.last_used_at, (select tokens.last_used_at from personal_access_tokens as tokens where tokens.id = devices.personal_access_token_id))';

        $query->orderByRaw("{$usedAt} is null")
            ->orderByRaw("{$usedAt} desc")
            ->orderByDesc('id');
    }

    /**
     * When the device was last used: the later of its own mark (set when it
     * registers and each time a push reaches it) and the last request made
     * with the token the app signed in with, if any.
     */
    public function lastUsedAt(): ?CarbonInterface
    {
        $ownUse = $this->last_used_at;
        $tokenUse = $this->personalAccessToken?->last_used_at;

        if ($ownUse === null || $tokenUse === null) {
            return $ownUse ?? $tokenUse;
        }

        return $tokenUse->greaterThan($ownUse) ? $tokenUse : $ownUse;
    }

    /**
     * Whether the device is a browser the web app registered, not a phone.
     */
    public function isBrowser(): bool
    {
        return $this->platform->slug === DevicePlatform::WEB;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DevicePlatform, $this>
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(DevicePlatform::class, 'device_platform_id');
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
