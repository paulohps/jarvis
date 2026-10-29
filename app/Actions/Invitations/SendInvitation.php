<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\InvitationReceived;

/**
 * Sends the `invitation` e-mail with the accept link of a plain token, to the
 * invited address and in the invitation's language. Used when inviting and
 * when resending.
 */
final readonly class SendInvitation
{
    public function __construct(private NotificationCenter $notificationCenter) {}

    public function __invoke(Invitation $invitation, string $token): void
    {
        $this->notificationCenter->dispatch(
            new InvitationReceived($invitation, Invitation::acceptUrl($token)),
            InvitationReceived::recipientFor($invitation),
        );
    }
}
