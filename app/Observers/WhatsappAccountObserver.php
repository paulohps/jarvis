<?php

namespace App\Observers;

use App\Models\ChatConversation;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;

/**
 * Drops the entries of an account's chats on the unified chat screen
 * before the account goes: the database takes its targets along by
 * cascade, which no model event sees.
 */
class WhatsappAccountObserver
{
    public function deleting(WhatsappAccount $account): void
    {
        ChatConversation::query()
            ->where('conversable_type', (new WhatsappTarget)->getMorphClass())
            ->whereIn('conversable_id', $account->targets()->select('id'))
            ->delete();
    }
}
