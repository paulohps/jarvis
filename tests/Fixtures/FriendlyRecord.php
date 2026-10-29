<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasFriendlyId;
use Illuminate\Database\Eloquent\Model;

/**
 * A throwaway model for the friendly id convention tests; its table is created
 * by the test itself.
 *
 * @property int $id
 * @property string $name
 */
class FriendlyRecord extends Model
{
    use HasFriendlyId;

    protected $table = 'friendly_records';

    protected $guarded = [];

    public static function idPrefix(): string
    {
        return 'frec';
    }
}
