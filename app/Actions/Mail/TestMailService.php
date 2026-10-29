<?php

namespace App\Actions\Mail;

use App\Models\MailService;
use App\Models\User;
use App\Services\Mail\MailServiceMailer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Sends a short test e-mail through the service, right away, to whoever
 * asked for it, and tells how it went (the transport's error when it
 * failed).
 */
final readonly class TestMailService
{
    /**
     * @return array{successful: bool, message: string}
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, MailService $service): array
    {
        Gate::forUser($actor)->authorize('use', $service);

        try {
            MailServiceMailer::for($service)->raw(
                __('This e-mail was sent by Jarvis through :name to check that it works.', ['name' => $service->name]),
                fn (Message $message): Message => $message->to($actor->email, $actor->name)->subject(__('Jarvis test e-mail')),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ['successful' => false, 'message' => $exception->getMessage()];
        }

        return ['successful' => true, 'message' => __('Test e-mail sent to :email.', ['email' => $actor->email])];
    }
}
