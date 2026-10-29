<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\GoogleCalendarAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Google account whose calendars a user reads on the agenda, read only
 * (scopes `calendar.events.readonly` and `calendar.calendarlist.readonly`).
 * Private: only its user sees its events. The refresh token is stored
 * encrypted; when Google stops accepting it the account keeps the error
 * (`failed_at`, `last_error`) until it is connected again.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property string $email
 * @property string $refresh_token
 * @property string|null $last_error
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'email', 'refresh_token', 'last_error', 'failed_at'])]
#[Hidden(['refresh_token'])]
class GoogleCalendarAccount extends Model
{
    /** @use HasFactory<GoogleCalendarAccountFactory> */
    use HasFactory, HasFriendlyId;

    public static function idPrefix(): string
    {
        return 'gca';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'failed_at' => 'datetime',
        ];
    }

    public function hasFailed(): bool
    {
        return $this->failed_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Its calendars, the primary one first.
     *
     * @return HasMany<GoogleCalendar, $this>
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(GoogleCalendar::class)->orderByDesc('is_primary')->orderBy('name');
    }
}
