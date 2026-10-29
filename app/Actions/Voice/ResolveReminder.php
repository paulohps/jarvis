<?php

namespace App\Actions\Voice;

use App\Models\Reminder;
use App\Models\User;
use App\Services\Voice\FuzzyMatcher;
use App\Services\Voice\VoiceResolution;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reminder a voice command completes (US-10.3), by its title, among
 * the reminders with an open occurrence; a friendly id (an option picked
 * after a clarification) is taken as it is. It resolves to the occurrence
 * to complete: the earliest open one, the undated one last. Only the
 * reminders of the speaker's workspaces with the `reminders` module are
 * candidates — never another workspace's, nor deleted ones.
 */
final readonly class ResolveReminder
{
    public function __construct(private FuzzyMatcher $matcher) {}

    public function __invoke(User $actor, string $spoken): VoiceResolution
    {
        $reminders = Reminder::query()
            ->visibleTo($actor)
            ->whereHas('occurrences', fn (Builder $query) => $query->whereNull('completed_at'))
            ->orderBy('title')
            ->get();

        $picked = $reminders->firstWhere('friendly_id', $spoken);

        $resolution = $picked instanceof Reminder
            ? VoiceResolution::resolved($picked)
            : $this->matcher->resolve(
                $spoken,
                $reminders,
                fn (Reminder $reminder): string => $reminder->title,
                fn (Reminder $reminder): string => $reminder->friendly_id,
            );

        if (! $resolution->match instanceof Reminder) {
            return $resolution->isAmbiguous() ? VoiceResolution::ambiguous($resolution->options) : VoiceResolution::notFound();
        }

        $occurrence = $resolution->match->occurrences()
            ->whereNull('completed_at')
            ->orderByRaw('due_at asc nulls last')
            ->firstOrFail();

        return VoiceResolution::resolved($occurrence->setRelation('reminder', $resolution->match));
    }
}
