<?php

namespace App\Actions\Chats;

use App\Models\ChatTag;
use App\Models\User;
use App\Models\WhatsappTarget;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates one of the user's tags for the chat screen, or renames and
 * recolors one: a name up to ChatTag::MAX_NAME_LENGTH, unique among the
 * user's tags whatever the case, and one of WhatsappTarget::PRIORITY_COLORS.
 *
 * Only the tag's owner, with the `chats` module.
 */
final readonly class SaveChatTag
{
    use AuthorizesChats;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ?ChatTag $tag, ?string $name, ?string $color): ChatTag
    {
        $this->authorizeChats($actor);

        if ($tag !== null && $tag->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        /** @var array{name: string, color: string} $validated */
        $validated = Validator::make(
            ['name' => filled($name) ? mb_trim((string) $name) : null, 'color' => $color],
            [
                'name' => ['required', 'string', 'max:'.ChatTag::MAX_NAME_LENGTH, function (string $attribute, mixed $value, Closure $fail) use ($actor, $tag): void {
                    $taken = $actor->chatTags()
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($tag !== null, fn (Builder $query) => $query->whereKeyNot($tag->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('You already have a tag with this name.'));
                    }
                }],
                'color' => ['required', Rule::in(array_keys(WhatsappTarget::PRIORITY_COLORS))],
            ],
            attributes: ['name' => __('name'), 'color' => __('color')],
        )->validate();

        $tag ??= new ChatTag(['user_id' => $actor->id]);
        $tag->fill($validated)->save();

        return $tag;
    }
}
