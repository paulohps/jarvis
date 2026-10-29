<?php

namespace App\Actions\Mail;

use App\Models\MailService;
use App\Models\MailServiceType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Stores the Google account the user just authorized (Gmail API, scope
 * `gmail.send`): a new service named as the user chose, or the same service
 * again when they reconnect it. The account's address is the sender and its
 * refresh token, encrypted, is what sends.
 *
 * Google gives the refresh token only with offline access and consent asked
 * again; without one the account cannot send, so nothing is stored.
 */
final readonly class ConnectGmailMailService
{
    /**
     * @param  array{name?: string|null, from_name?: string|null, is_global?: bool}  $settings  what the user filled in before going to Google
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, string $email, ?string $refreshToken, array $settings = [], ?MailService $service = null): MailService
    {
        if ($service !== null) {
            Gate::forUser($actor)->authorize('update', $service);
        }

        if (blank($refreshToken)) {
            throw ValidationException::withMessages([
                'google' => __('Google did not allow sending. Connect again and allow Jarvis to send e-mail on your behalf.'),
            ]);
        }

        $service ??= new MailService([
            'mail_service_type_id' => MailServiceType::idFor(MailServiceType::GMAIL),
            'user_id' => $actor->id,
            'name' => filled($settings['name'] ?? null) ? mb_substr((string) $settings['name'], 0, 100) : $email,
            'from_name' => filled($settings['from_name'] ?? null) ? $settings['from_name'] : $actor->name,
            'is_global' => $actor->isSuperAdmin() && ($settings['is_global'] ?? false),
        ]);

        $service->fill([
            'from_address' => $email,
            'secret' => $refreshToken,
            'secret_last_four' => null,
        ])->save();

        return $service;
    }
}
