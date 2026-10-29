<?php

namespace App\Services\Voice;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Picks the record a spoken name means (US-10.3), for the voice resolvers.
 *
 * Names are compared without case, accents or punctuation, word by word,
 * leaving out articles and fillers ("dessa", "the", "lista"): each spoken
 * word scores its best match among the candidate's words (equal, a prefix,
 * or a typo away), and the candidate scores their average. The full name
 * said exactly scores 1.
 *
 * It never guesses: a candidate is picked only when it scores at least
 * MIN_SCORE and no other one comes within MARGIN of it; otherwise every
 * candidate that close is an option to ask about.
 */
final class FuzzyMatcher
{
    public const float MIN_SCORE = 0.6;

    public const float MARGIN = 0.15;

    public const int MAX_OPTIONS = 10;

    /**
     * Words that never tell one name from another.
     */
    private const array FILLERS = [
        'a', 'o', 'as', 'os', 'um', 'uma', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'na', 'no', 'nas', 'nos',
        'para', 'pra', 'pro', 'essa', 'esse', 'esta', 'este', 'dessa', 'desse', 'desta', 'deste', 'nessa',
        'nesse', 'nesta', 'neste', 'minha', 'meu', 'minhas', 'meus', 'lista', 'listas', 'contato', 'grupo',
        'lembrete', 'the', 'an', 'of', 'for', 'to', 'my', 'this', 'that', 'list', 'contact', 'group', 'reminder',
    ];

    /**
     * @template TModel of Model
     *
     * @param  iterable<TModel>  $candidates
     * @param  Closure(TModel): string  $label
     * @param  Closure(TModel): string  $id
     */
    public function resolve(string $spoken, iterable $candidates, Closure $label, Closure $id): VoiceResolution
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $score = $this->score($spoken, $label($candidate));

            if ($score >= self::MIN_SCORE) {
                $scored[] = ['candidate' => $candidate, 'score' => $score];
            }
        }

        if ($scored === []) {
            return VoiceResolution::notFound();
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $close = array_values(array_filter(
            $scored,
            fn (array $entry): bool => $entry['score'] >= $scored[0]['score'] - self::MARGIN,
        ));

        if (count($close) === 1) {
            return VoiceResolution::resolved($close[0]['candidate']);
        }

        return VoiceResolution::ambiguous(self::options(array_column($close, 'candidate'), $label, $id));
    }

    /**
     * Every candidate as an option, for a name left unsaid with more than
     * one to choose from.
     *
     * @template TModel of Model
     *
     * @param  iterable<TModel>  $candidates
     * @param  Closure(TModel): string  $label
     * @param  Closure(TModel): string  $id
     * @return list<array{id: string, label: string}>
     */
    public static function options(iterable $candidates, Closure $label, Closure $id): array
    {
        $options = [];

        foreach ($candidates as $candidate) {
            $options[] = ['id' => $id($candidate), 'label' => $label($candidate)];
        }

        return array_slice($options, 0, self::MAX_OPTIONS);
    }

    /**
     * How well `$candidate` matches what was said, from 0 to 1.
     */
    public function score(string $spoken, string $candidate): float
    {
        $spokenNormalized = $this->normalize($spoken);
        $candidateNormalized = $this->normalize($candidate);

        if ($spokenNormalized === '' || $candidateNormalized === '') {
            return 0.0;
        }

        if ($spokenNormalized === $candidateNormalized) {
            return 1.0;
        }

        $spokenWords = $this->words($spokenNormalized);
        $candidateWords = $this->words($candidateNormalized);

        $total = 0.0;

        foreach ($spokenWords as $spokenWord) {
            $best = 0.0;

            foreach ($candidateWords as $candidateWord) {
                $best = max($best, $this->wordScore($spokenWord, $candidateWord));
            }

            $total += $best;
        }

        // A whole-word match is just below the exact name, so an exact name
        // and a longer one sharing the word stay close enough to ask about.
        return min(0.95, $total / count($spokenWords));
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }

    /**
     * The meaningful words, or every word when they are all fillers.
     *
     * @return non-empty-list<string>
     */
    private function words(string $normalized): array
    {
        $words = explode(' ', $normalized);
        $meaningful = array_values(array_diff($words, self::FILLERS));

        return $meaningful === [] ? $words : $meaningful;
    }

    private function wordScore(string $spoken, string $candidate): float
    {
        if ($spoken === $candidate) {
            return 1.0;
        }

        $shorter = min(strlen($spoken), strlen($candidate));

        if ($shorter >= 3 && (str_starts_with($candidate, $spoken) || str_starts_with($spoken, $candidate))) {
            return 0.9;
        }

        $similarity = 1 - levenshtein($spoken, $candidate) / max(strlen($spoken), strlen($candidate));

        return $similarity >= 0.75 ? $similarity : 0.0;
    }
}
