<?php

namespace App\Livewire\Admin\Ai;

use App\Actions\Ai\SaveAiModelPrice;
use App\Enums\AiModel;
use App\Models\AiModelPrice;
use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\AiUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The AI usage of the admin area: for the period picked (today, the last 7
 * or 30 days, this month — days of the super admin's timezone), the calls
 * RecordAiUsage wrote down, their tokens and the share of the input read
 * from the providers' prompt cache, a bar per day, then the same split by
 * model, by use of the AI and by user, and the latest failures.
 *
 * The spend is an estimate: it comes from the prices the super admin types
 * per model (US dollars per million tokens, SaveAiModelPrice) in the last
 * section, which lists the models of the stored connections and every model
 * that was used. A model without a price shows its tokens only, and the
 * total says it is partial.
 *
 * @property-read EloquentCollection<int, AiUsage> $usages
 * @property-read Collection<string, AiModelPrice> $prices
 * @property-read Collection<int, AiProvider> $providers
 */
#[Layout('layouts::app-shell')]
class AiUsageReport extends Component
{
    public const array PERIODS = ['today', '7d', '30d', 'month'];

    #[Url]
    public string $period = '30d';

    /**
     * The model whose prices are being edited ("provider slug|model").
     */
    public ?string $editingPrice = null;

    /**
     * @var array{input: string, output: string, cache_read: string, cache_write: string}
     */
    public array $price = ['input' => '', 'output' => '', 'cache_read' => '', 'cache_write' => ''];

    public ?string $status = null;

    public function mount(): void
    {
        Gate::forUser($this->actor())->authorize('super-admin');

        if (! in_array($this->period, self::PERIODS, true)) {
            $this->period = '30d';
        }
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, self::PERIODS, true)) {
            $this->period = '30d';
        }

        unset($this->usages);
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return [
            'today' => __('Today'),
            '7d' => __('7 days'),
            '30d' => __('30 days'),
            'month' => __('This month'),
        ];
    }

    /**
     * The first and last instant of the period, in the super admin's timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $now = CarbonImmutable::now($this->actor()->timezone);

        $from = match ($this->period) {
            'today' => $now->startOfDay(),
            '7d' => $now->subDays(6)->startOfDay(),
            'month' => $now->startOfMonth(),
            default => $now->subDays(29)->startOfDay(),
        };

        return [$from, $now->endOfDay()];
    }

    /**
     * @return EloquentCollection<int, AiUsage>
     */
    #[Computed]
    public function usages(): EloquentCollection
    {
        [$from, $until] = $this->range();

        return AiUsage::query()
            ->between($from->utc(), $until->utc())
            ->latest('created_at')
            ->get();
    }

    /**
     * The prices, by "provider id|model", the removed ones too: they still
     * price the usage of before their removal.
     *
     * @return Collection<string, AiModelPrice>
     */
    #[Computed]
    public function prices(): Collection
    {
        return AiModelPrice::withTrashed()->get()->keyBy(fn (AiModelPrice $price): string => $price->ai_provider_id.'|'.$price->model);
    }

    /**
     * @return Collection<int, AiProvider>
     */
    #[Computed]
    public function providers(): Collection
    {
        return AiProvider::query()->get()->keyBy('id');
    }

    /**
     * The totals of the period.
     *
     * @return array{calls: int, failed: int, input: int, output: int, cache_read: int, cache_write: int, cache_share: float|null, cost: float, cost_complete: bool}
     */
    public function summary(): array
    {
        $totals = $this->totalsOf($this->usages);
        $input = $totals['input'];

        return [
            ...$totals,
            'cache_share' => $input > 0 ? $totals['cache_read'] / $input : null,
        ];
    }

    /**
     * The calls, tokens and spend of each day of the period, oldest first.
     *
     * @return list<array{date: CarbonImmutable, tokens: int, calls: int, cost: float}>
     */
    public function daily(): array
    {
        [$from, $until] = $this->range();
        $timezone = $this->actor()->timezone;

        $byDay = $this->usages->groupBy(fn (AiUsage $usage): string => $usage->created_at->setTimezone($timezone)->toDateString());

        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($until); $day = $day->addDay()) {
            $totals = $this->totalsOf($byDay->get($day->toDateString(), collect()));

            $days[] = [
                'date' => $day,
                'tokens' => $totals['input'] + $totals['output'],
                'calls' => $totals['calls'],
                'cost' => $totals['cost'],
            ];
        }

        return $days;
    }

    /**
     * One row per provider and model used in the period, the most tokens first.
     *
     * @return list<array{key: string, provider: string, model: string, calls: int, failed: int, input: int, output: int, cache_read: int, cache_write: int, cost: float, cost_complete: bool}>
     */
    public function byModel(): array
    {
        return array_values($this->usages
            ->groupBy(fn (AiUsage $usage): string => $usage->ai_provider_id.'|'.$usage->model)
            ->map(function (Collection $usages, string $key): array {
                $totals = $this->totalsOf($usages);
                /** @var AiUsage $first */
                $first = $usages->first();

                return [
                    'key' => $key,
                    'provider' => (string) __($this->providers->get($first->ai_provider_id)->name),
                    'model' => AiModel::labelFor($first->model),
                    ...$totals,
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['input'] + $row['output'])
            ->values()
            ->all());
    }

    /**
     * One row per use of the AI in the period (the connection tests apart).
     *
     * @return list<array{key: string, label: string, calls: int, failed: int, input: int, output: int, cost: float, cost_complete: bool}>
     */
    public function byPurpose(): array
    {
        $purposes = AiPurpose::query()->get()->keyBy('id');

        return $this->groupedBy('ai_purpose_id', fn (?int $id): string => $id === null
            ? __('Connection tests')
            : __($purposes->get($id)->name ?? '—'));
    }

    /**
     * One row per user the calls were made for in the period.
     *
     * @return list<array{key: string, label: string, calls: int, failed: int, input: int, output: int, cost: float, cost_complete: bool}>
     */
    public function byUser(): array
    {
        $names = User::query()->whereIn('id', $this->usages->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return $this->groupedBy('user_id', fn (?int $id): string => $id === null ? __('Nobody') : ($names->get($id) ?? '—'));
    }

    /**
     * The latest failed calls of the period.
     *
     * @return Collection<int, AiUsage>
     */
    public function failures(): Collection
    {
        return $this->usages->where('succeeded', false)->take(5)->values();
    }

    /**
     * Every model a price can be typed for: the ones the stored connections
     * offer and the ones ever used, by provider and model.
     *
     * @return list<array{key: string, provider_slug: string, provider: string, model: string, label: string, price: AiModelPrice|null}>
     */
    public function priceableModels(): array
    {
        $offered = AiProviderConnection::query()->get(['ai_provider_id', 'models'])
            ->flatMap(fn (AiProviderConnection $connection): array => array_map(
                fn (string $model): array => [$connection->ai_provider_id, $model],
                $connection->models,
            ));

        $used = AiUsage::query()->distinct()->get(['ai_provider_id', 'model'])
            ->map(fn (AiUsage $usage): array => [$usage->ai_provider_id, $usage->model]);

        return array_values($offered->concat($used)
            ->unique(fn (array $pair): string => $pair[0].'|'.$pair[1])
            ->map(
                /** @param array{int, string} $pair */
                function (array $pair): array {
                    [$providerId, $model] = $pair;
                    $provider = $this->providers->get($providerId);

                    return [
                        'key' => $provider->slug.'|'.$model,
                        'provider_slug' => $provider->slug,
                        'provider' => (string) __($provider->name),
                        'model' => $model,
                        'label' => AiModel::labelFor($model),
                        'price' => $this->currentPrice($providerId, $model),
                    ];
                },
            )
            ->sortBy(fn (array $row): string => $row['provider'].'|'.$row['label'])
            ->values()
            ->all());
    }

    public function editPrice(string $key): void
    {
        [$provider, $model] = explode('|', $key, 2);
        $price = $this->currentPrice(AiProvider::idFor($provider), $model);

        $this->editingPrice = $key;
        $this->price = [
            'input' => $this->plain($price?->input_per_million),
            'output' => $this->plain($price?->output_per_million),
            'cache_read' => $this->plain($price?->cache_read_per_million),
            'cache_write' => $this->plain($price?->cache_write_per_million),
        ];
        $this->resetValidation();
    }

    public function cancelPrice(): void
    {
        $this->reset('editingPrice', 'price');
        $this->resetValidation();
    }

    public function savePrice(SaveAiModelPrice $savePrice): void
    {
        if ($this->editingPrice === null) {
            return;
        }

        [$provider, $model] = explode('|', $this->editingPrice, 2);

        $savePrice($this->actor(), $provider, $model, $this->price);

        $this->status = __('The prices of :model were saved.', ['model' => AiModel::labelFor($model)]);
        $this->reset('editingPrice', 'price');
        unset($this->prices);
    }

    /**
     * Removes the price of a model ("provider slug|model"); the usage of
     * before keeps its cost.
     */
    public function removePrice(string $key, SaveAiModelPrice $savePrice): void
    {
        [$provider, $model] = explode('|', $key, 2);

        $savePrice($this->actor(), $provider, $model, ['input' => null, 'output' => null]);

        $this->status = __('The price of :model was removed. What was used before keeps its cost.', ['model' => AiModel::labelFor($model)]);
        $this->reset('editingPrice', 'price');
        unset($this->prices);
    }

    /**
     * The price a model has now: none when it was removed.
     */
    private function currentPrice(int $providerId, string $model): ?AiModelPrice
    {
        $price = $this->prices->get($providerId.'|'.$model);

        return $price?->deleted_at === null ? $price : null;
    }

    /**
     * A token count, short: 950, 12.3k, 4.56M.
     */
    public function tokens(int $count): string
    {
        return match (true) {
            $count >= 1_000_000 => rtrim(rtrim(number_format($count / 1_000_000, 2, '.', ''), '0'), '.').'M',
            $count >= 1_000 => rtrim(rtrim(number_format($count / 1_000, 1, '.', ''), '0'), '.').'k',
            default => (string) $count,
        };
    }

    /**
     * A spend in US dollars: four decimals under a dollar, two above.
     */
    public function dollars(float $amount): string
    {
        return 'US$ '.number_format($amount, $amount > 0 && $amount < 1 ? 4 : 2, '.', ',');
    }

    public function render(): View
    {
        return view('livewire.admin.ai.ai-usage-report')->title(__('AI usage'));
    }

    /**
     * The rows of the period grouped by a column, the most tokens first.
     *
     * @param  callable(int|null): string  $label
     * @return list<array{key: string, label: string, calls: int, failed: int, input: int, output: int, cost: float, cost_complete: bool}>
     */
    private function groupedBy(string $column, callable $label): array
    {
        return array_values($this->usages
            ->groupBy(fn (AiUsage $usage): string => (string) $usage->{$column})
            ->map(fn (Collection $usages, string $key): array => [
                'key' => $key === '' ? 'none' : $key,
                'label' => $label($key === '' ? null : (int) $key),
                ...$this->totalsOf($usages),
            ])
            ->sortByDesc(fn (array $row): int => $row['input'] + $row['output'])
            ->values()
            ->all());
    }

    /**
     * The calls and tokens of the rows, and their spend at the typed prices
     * (`cost_complete` is false when a row with tokens has no price).
     *
     * @param  Collection<int, AiUsage>  $usages
     * @return array{calls: int, failed: int, input: int, output: int, cache_read: int, cache_write: int, cost: float, cost_complete: bool}
     */
    private function totalsOf(Collection $usages): array
    {
        $cost = 0.0;
        $complete = true;

        foreach ($usages as $usage) {
            $price = $this->prices->get($usage->ai_provider_id.'|'.$usage->model);

            if ($price === null || ! $price->pricesUsageAt($usage->created_at)) {
                $complete = $complete && $usage->input_tokens + $usage->output_tokens === 0;

                continue;
            }

            $cost += $price->costOf($usage->input_tokens, $usage->output_tokens, $usage->cache_read_tokens, $usage->cache_write_tokens);
        }

        return [
            'calls' => $usages->count(),
            'failed' => $usages->where('succeeded', false)->count(),
            'input' => (int) $usages->sum('input_tokens'),
            'output' => (int) $usages->sum('output_tokens'),
            'cache_read' => (int) $usages->sum('cache_read_tokens'),
            'cache_write' => (int) $usages->sum('cache_write_tokens'),
            'cost' => $cost,
            'cost_complete' => $complete,
        ];
    }

    /**
     * A stored price as the field shows it, without trailing zeros.
     */
    private function plain(?string $price): string
    {
        return $price === null ? '' : rtrim(rtrim($price, '0'), '.');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
