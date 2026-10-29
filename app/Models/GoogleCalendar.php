<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\GoogleCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One calendar of a connected Google account, as Google lists it
 * (`google_id`, its name and color). Hidden ones (`is_visible` false) stay
 * off the agenda.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $google_calendar_account_id
 * @property string $google_id
 * @property string $name
 * @property string|null $color
 * @property bool $is_primary
 * @property bool $is_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GoogleCalendarAccount $account
 */
#[Fillable(['google_calendar_account_id', 'google_id', 'name', 'color', 'is_primary', 'is_visible'])]
class GoogleCalendar extends Model
{
    /** @use HasFactory<GoogleCalendarFactory> */
    use HasFactory, HasFriendlyId;

    public static function idPrefix(): string
    {
        return 'gcal';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_visible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GoogleCalendarAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarAccount::class, 'google_calendar_account_id');
    }
}
