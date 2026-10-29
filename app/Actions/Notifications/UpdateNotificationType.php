<?php

namespace App\Actions\Notifications;

use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The super admin switches a notification type on or off, or shows it in
 * the profile or not (US-5.5, ported from my-finance). A type switched off
 * is sent to nobody, whatever their preferences, and what is already queued
 * is dropped by the worker; a type kept out of the profile is still sent,
 * following each user's earlier choice or the channel default.
 *
 * Saving through the model drops the cached settings
 * (`NotificationSettingsObserver`), so the next dispatch already obeys.
 */
final readonly class UpdateNotificationType
{
    /**
     * @param  array{is_active?: mixed, is_visible_in_profile?: mixed}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, NotificationType $type, array $input): NotificationType
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{is_active?: bool, is_visible_in_profile?: bool} $validated */
        $validated = Validator::make($input, [
            'is_active' => ['sometimes', 'boolean'],
            'is_visible_in_profile' => ['sometimes', 'boolean'],
        ])->validate();

        $type->update($validated);

        return $type;
    }
}
