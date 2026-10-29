<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappMessage;

/**
 * A scheduled WhatsApp message goes out through its author's own account
 * (US-7.3), so only the author sees, edits, sends, pauses, cancels or
 * deletes it. Anyone else — the super admin included — gets a 403.
 */
class WhatsappMessagePolicy
{
    public function view(User $user, WhatsappMessage $message): bool
    {
        return $this->owns($user, $message);
    }

    public function update(User $user, WhatsappMessage $message): bool
    {
        return $this->owns($user, $message);
    }

    public function delete(User $user, WhatsappMessage $message): bool
    {
        return $this->owns($user, $message);
    }

    private function owns(User $user, WhatsappMessage $message): bool
    {
        return $message->user_id === $user->id;
    }
}
