<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The color the user gives each chat priority, one of
 * WhatsappTarget::PRIORITY_COLORS: every chat with that priority shows it.
 * A priority left out keeps its default (DEFAULT_PRIORITY_COLORS).
 *
 * Needs the `whatsapp` module.
 */
final readonly class SetWhatsappPriorityColors
{
    use AuthorizesWhatsappModule;

    /**
     * @param  array<int|string, string|null>  $colors  priority => color name
     * @return array<int, string>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, array $colors): array
    {
        $this->authorizeWhatsapp($user);

        $priorities = range(WhatsappTarget::HIGHEST_PRIORITY, WhatsappTarget::LOWEST_PRIORITY);
        $input = [];

        foreach ($priorities as $priority) {
            $input[(string) $priority] = filled($colors[$priority] ?? $colors[(string) $priority] ?? null) ? (string) ($colors[$priority] ?? $colors[(string) $priority]) : null;
        }

        /** @var array<string, string|null> $validated */
        $validated = Validator::make(
            $input,
            array_fill_keys(array_keys($input), ['nullable', Rule::in(array_keys(WhatsappTarget::PRIORITY_COLORS))]),
        )->validate();

        $user->forceFill(['whatsapp_priority_colors' => array_filter($validated)])->save();

        return $user->whatsappPriorityColors();
    }
}
