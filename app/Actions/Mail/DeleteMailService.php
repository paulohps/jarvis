<?php

namespace App\Actions\Mail;

use App\Models\MailService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a mail service. Whatever picked it (notification types, invoice
 * recurrences) goes back to the default mailer (`nullOnDelete`).
 */
final readonly class DeleteMailService
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, MailService $service): void
    {
        Gate::forUser($actor)->authorize('delete', $service);

        // Through the models, so the cached notification settings are dropped.
        SaveMailService::releaseFromOthers($service);

        $service->delete();
    }
}
