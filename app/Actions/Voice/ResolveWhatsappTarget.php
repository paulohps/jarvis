<?php

namespace App\Actions\Voice;

use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Services\Voice\FuzzyMatcher;
use App\Services\Voice\VoiceResolution;
use Illuminate\Database\Eloquent\Collection;

/**
 * The contact or group a voice command sends to (US-10.3), by its name,
 * among the targets of the user's own accounts only — never another
 * user's. An account nickname, when said, narrows them to that account. A
 * friendly id (an option picked after a clarification) is taken as it is.
 * The same person on two accounts is two options.
 */
final readonly class ResolveWhatsappTarget
{
    public function __construct(private FuzzyMatcher $matcher) {}

    public function __invoke(User $actor, string $spoken, ?string $account = null): VoiceResolution
    {
        /** @var Collection<int, WhatsappAccount> $accounts */
        $accounts = $actor->whatsappAccounts()->orderBy('nickname')->get();

        if ($account !== null) {
            $named = $accounts->filter(fn (WhatsappAccount $candidate): bool => $candidate->friendly_id === $account
                || $this->matcher->score($account, $candidate->nickname) >= FuzzyMatcher::MIN_SCORE);

            $accounts = $named->isEmpty() ? $accounts : $named;
        }

        $targets = WhatsappTarget::query()
            ->whereIn('whatsapp_account_id', $accounts->modelKeys())
            ->orderBy('name')
            ->get()
            ->each(fn (WhatsappTarget $target) => $target->setRelation('account', $accounts->find($target->whatsapp_account_id)));

        $picked = $targets->firstWhere('friendly_id', $spoken);

        if ($picked !== null) {
            return VoiceResolution::resolved($picked);
        }

        $several = $accounts->count() > 1;

        return $this->matcher->resolve(
            $spoken,
            $targets,
            fn (WhatsappTarget $target): string => $several
                ? "{$target->displayName()} ({$target->account->nickname})"
                : $target->displayName(),
            fn (WhatsappTarget $target): string => $target->friendly_id,
        );
    }
}
