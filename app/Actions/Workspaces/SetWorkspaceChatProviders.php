<?php

namespace App\Actions\Workspaces;

use App\Models\ChatProvider;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The super admin sets which chat channels (ChatProvider slugs) a
 * workspace may use: exactly the slugs given. A member uses a channel
 * only when it is on for the workspace and for them
 * (SetMemberChatProviders); switching one off here hides its
 * conversations from every member of the workspace at once, keeping
 * their accounts and data.
 */
final readonly class SetWorkspaceChatProviders
{
    /**
     * @param  list<string>|mixed  $providerSlugs
     * @return list<string> the slugs switched on now
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, Workspace $workspace, mixed $providerSlugs): array
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{providers: list<string>} $validated */
        $validated = Validator::make(['providers' => $providerSlugs], [
            'providers' => ['present', 'array'],
            'providers.*' => ['string', 'distinct', Rule::exists('chat_providers', 'slug')->where('is_active', true)],
        ])->validate();

        $ids = ChatProvider::query()->whereIn('slug', $validated['providers'])->pluck('id')->all();

        $workspace->chatProviders()->syncWithPivotValues($ids, ['enabled_by_id' => $actor->id], detaching: true);
        $workspace->unsetRelation('chatProviders');

        return array_values($workspace->chatProviders()->orderBy('sort_order')->get()->map(fn (ChatProvider $provider): string => $provider->slug)->values()->all());
    }
}
