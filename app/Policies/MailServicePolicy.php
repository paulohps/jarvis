<?php

namespace App\Policies;

use App\Models\MailService;
use App\Models\User;

/**
 * A mail service belongs to whoever created it. Its owner sees it, sends
 * through it, edits and removes it. A global one is also offered to everyone
 * (they may send through it, not see its settings), and the super admin
 * manages it as well.
 */
class MailServicePolicy
{
    /**
     * Sending through the service: picking it for an invoice or testing it.
     */
    public function use(User $user, MailService $service): bool
    {
        return $service->isUsableBy($user);
    }

    public function update(User $user, MailService $service): bool
    {
        return $this->manages($user, $service);
    }

    public function delete(User $user, MailService $service): bool
    {
        return $this->manages($user, $service);
    }

    private function manages(User $user, MailService $service): bool
    {
        return $service->user_id === $user->id || ($service->is_global && $user->isSuperAdmin());
    }
}
