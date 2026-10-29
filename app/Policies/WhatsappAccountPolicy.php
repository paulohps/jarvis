<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappAccount;

/**
 * A WhatsApp account belongs to one user and is never shared (US-7.1): only
 * its owner sees it, sends through it, reconnects it or removes it. Anyone
 * else — the super admin included — gets a 403.
 */
class WhatsappAccountPolicy
{
    public function view(User $user, WhatsappAccount $account): bool
    {
        return $this->owns($user, $account);
    }

    /**
     * Sending through the account and reconnecting it.
     */
    public function use(User $user, WhatsappAccount $account): bool
    {
        return $this->owns($user, $account);
    }

    public function delete(User $user, WhatsappAccount $account): bool
    {
        return $this->owns($user, $account);
    }

    private function owns(User $user, WhatsappAccount $account): bool
    {
        return $account->user_id === $user->id;
    }
}
