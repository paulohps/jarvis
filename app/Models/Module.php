<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A functional unit of the system, switched on or off per user by the super
 * admin (Key Concept "Module"). A row in `module_user` means the module is on
 * for that user.
 *
 * @property string|null $description
 * @property bool $is_available_on_mobile
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'description', 'is_available_on_mobile', 'sort_order', 'is_active'])]
class Module extends Lookup
{
    public const string INVOICES = 'invoices';

    public const string WHATSAPP = 'whatsapp';

    public const string SHOPPING = 'shopping';

    public const string REMINDERS = 'reminders';

    public const string CHATS = 'chats';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available_on_mobile' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Users with this module on.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('enabled_by_id')
            ->withTimestamps();
    }

    /**
     * Invitations that switch this module on when accepted.
     *
     * @return BelongsToMany<Invitation, $this>
     */
    public function invitations(): BelongsToMany
    {
        return $this->belongsToMany(Invitation::class)->withTimestamps();
    }
}
