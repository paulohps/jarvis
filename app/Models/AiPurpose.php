<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What the AI is used for: each use may get its own connection and model
 * (AiPurposeAssignment); without one it takes the connection in use.
 *
 * @property-read AiPurposeAssignment|null $assignment
 */
class AiPurpose extends Lookup
{
    public const string VOICE_COMMAND = 'voice_command';

    public const string WHATSAPP_REPLY = 'whatsapp_reply';

    public const string CHAT_REPLY = 'chat_reply';

    /**
     * @return HasOne<AiPurposeAssignment, $this>
     */
    public function assignment(): HasOne
    {
        return $this->hasOne(AiPurposeAssignment::class);
    }
}
