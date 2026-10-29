<?php

namespace App\Models;

use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Socialite identity linked to a user: at most one per provider per user,
 * and a provider identity belongs to a single user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $social_provider_id
 * @property string $provider_user_id
 * @property string|null $email
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read SocialProvider $provider
 */
#[Fillable(['user_id', 'social_provider_id', 'provider_user_id', 'email', 'last_used_at'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SocialProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(SocialProvider::class, 'social_provider_id');
    }
}
