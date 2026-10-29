<?php

namespace App\Actions\Users;

use App\Events\UserDeactivated;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The super admin deactivates a user (US-2.2) without deleting anything:
 * `deactivated_at` is set, every Sanctum token is deleted (the apps get a
 * 401 on their next call), the stored web sessions are deleted and the
 * "remember me" token is replaced, so no browser stays signed in.
 * `EnsureUserIsActive` signs out a session the driver cannot delete.
 *
 * Then `UserDeactivated` is dispatched. The super admin cannot be
 * deactivated, by themselves or anyone else.
 */
final readonly class DeactivateUser
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException when the user is the super admin or already deactivated
     */
    public function __invoke(User $actor, User $user): User
    {
        Gate::forUser($actor)->authorize('super-admin');

        if ($user->is($actor) || $user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user' => __('You cannot deactivate yourself.'),
            ]);
        }

        if ($user->isDeactivated()) {
            throw ValidationException::withMessages([
                'user' => __('This user is already deactivated.'),
            ]);
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'deactivated_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }
        });

        UserDeactivated::dispatch($user);

        return $user;
    }
}
