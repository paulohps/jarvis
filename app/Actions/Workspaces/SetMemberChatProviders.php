<?php

namespace App\Actions\Workspaces;

use App\Models\ChatProvider;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The super admin sets which chat channels (ChatProvider slugs) a member
 * may use in a workspace: exactly the slugs given. The member uses a
 * channel only when it is on for the workspace too
 * (SetWorkspaceChatProviders) and they have the `chats` module there.
 * The super admin has every channel without rows.
 */
final readonly class SetMemberChatProviders
{
    /**
     * @param  list<string>|mixed  $providerSlugs
     * @return list<string> the slugs switched on now
     *
     * @throws AuthorizationException
     * @throws ValidationException when the member is the super admin or a slug is unknown
     */
    public function __invoke(User $actor, WorkspaceMembership $membership, mixed $providerSlugs): array
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{providers: list<string>} $validated */
        $validated = Validator::make(['providers' => $providerSlugs], [
            'providers' => ['present', 'array'],
            'providers.*' => ['string', 'distinct', Rule::exists('chat_providers', 'slug')->where('is_active', true)],
        ])->validate();

        if ($membership->user->isSuperAdmin()) {
            throw ValidationException::withMessages(['user' => __('The super admin always has every chat channel.')]);
        }

        $ids = ChatProvider::query()->whereIn('slug', $validated['providers'])->pluck('id')->all();

        $membership->chatProviders()->syncWithPivotValues($ids, ['enabled_by_id' => $actor->id], detaching: true);
        $membership->unsetRelation('chatProviders');
        $membership->user->forgetMemberships();

        return array_values($membership->chatProviders()->orderBy('sort_order')->get()->map(fn (ChatProvider $provider): string => $provider->slug)->values()->all());
    }
}
