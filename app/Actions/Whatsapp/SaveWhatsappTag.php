<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates one of the user's chat tags, or renames and recolors one: a name
 * up to WhatsappTag::MAX_NAME_LENGTH, unique among the user's tags whatever
 * the case, and one
 * of WhatsappTarget::PRIORITY_COLORS.
 *
 * Only the tag's owner, with the `whatsapp` module.
 */
final readonly class SaveWhatsappTag
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, ?WhatsappTag $tag, ?string $name, ?string $color): WhatsappTag
    {
        $this->authorizeWhatsapp($user);

        if ($tag !== null && $tag->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        /** @var array{name: string, color: string} $validated */
        $validated = Validator::make(
            ['name' => filled($name) ? mb_trim((string) $name) : null, 'color' => $color],
            [
                'name' => ['required', 'string', 'max:'.WhatsappTag::MAX_NAME_LENGTH, function (string $attribute, mixed $value, Closure $fail) use ($user, $tag): void {
                    $taken = $user->whatsappTags()
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

        $tag ??= new WhatsappTag(['user_id' => $user->id]);
        $tag->fill($validated)->save();

        return $tag;
    }
}
