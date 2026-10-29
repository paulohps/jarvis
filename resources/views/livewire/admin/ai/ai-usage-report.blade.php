{{--
    The AI usage: the period, the totals (calls, tokens, cache share,
    estimated spend), a bar of tokens per day, the split by model, by use and
    by user, the latest failures, then the price of each model, typed by the
    super admin, which the spend is estimated from.
--}}
@php
    $summary = $this->summary();
    $daily = $this->daily();
    $peak = max(1, ...array_column($daily, 'tokens'));
    $tableHeading = 'px-3 py-2 text-[0.8125rem] font-normal text-secondary-label uppercase';
    $cost = fn (array $row): string => $row['cost_complete'] ? $this->dollars($row['cost']) : ($row['cost'] > 0 ? '≥ '.$this->dollars($row['cost']) : '—');
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.segmented-control name="period"
                                wire:model.live="period"
                                :label="__('Period')"
                                :value="$period"
                                :options="$this->periodOptions()"
                                class="sm:min-w-80"
                                data-ai-usage-period />

        <x-button :text="__('AI provider')" icon="sparkles" sm flat color="secondary"
                  :href="route('admin.ai-providers.index')" wire:navigate />
    </div>

    @if (filled($status))
        <p role="status" data-ai-usage-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-ai-usage-summary>
        @foreach ([
            ['calls', __('Calls'), number_format($summary['calls'], 0, '.', ','), $summary['failed'] > 0 ? __(':count failed', ['count' => $summary['failed']]) : null],
            ['tokens', __('Tokens'), $this->tokens($summary['input'] + $summary['output']), __(':input in · :output out', ['input' => $this->tokens($summary['input']), 'output' => $this->tokens($summary['output'])])],
            ['cache', __('Input from cache'), $summary['cache_share'] === null ? '—' : number_format($summary['cache_share'] * 100, 1).'%', __(':read read · :write written', ['read' => $this->tokens($summary['cache_read']), 'write' => $this->tokens($summary['cache_write'])])],
            ['cost', __('Estimated spend'), $cost($summary), $summary['cost_complete'] ? null : __('Some models have no price')],
        ] as [$key, $label, $value, $detail])
            <div wire:key="ai-usage-summary-{{ $key }}" data-ai-usage-total="{{ $key }}"
                 class="flex flex-col gap-1 rounded-card bg-secondary-grouped-background p-4 shadow-soft">
                <dt class="text-[0.8125rem] text-secondary-label">{{ $label }}</dt>
                <dd class="text-2xl font-semibold tracking-tight text-label tabular-nums">{{ $value }}</dd>
                @if ($detail !== null)
                    <dd class="text-[0.8125rem] text-secondary-label">{{ $detail }}</dd>
                @endif
            </div>
        @endforeach
    </dl>

    @if ($summary['calls'] === 0)
        <x-ui.empty-state icon="chart-bar"
                          :title="__('No AI call in this period')"
                          :description="__('Every voice command, WhatsApp draft and connection test is counted here from now on.')" />
    @else
        <x-ui.settings-section :title="__('Tokens per day')">
            <div class="flex h-40 items-end gap-0.5" role="list" aria-label="{{ __('Tokens per day') }}" data-ai-usage-daily>
                @foreach ($daily as $day)
                    @php
                        $label = $day['date']->translatedFormat('d M').': '.__(':tokens tokens in :calls calls', ['tokens' => $this->tokens($day['tokens']), 'calls' => $day['calls']]).($day['cost'] > 0 ? ' · '.$this->dollars($day['cost']) : '');
                    @endphp
                    <div role="listitem" wire:key="ai-usage-day-{{ $day['date']->toDateString() }}"
                         class="group relative flex h-full min-w-0 flex-1 items-end" title="{{ $label }}">
                        <span class="sr-only">{{ $label }}</span>
                        <span aria-hidden="true"
                              class="w-full rounded-t-[4px] bg-tint-fill transition-opacity group-hover:opacity-80"
                              style="height: {{ $day['tokens'] > 0 ? max(2, round($day['tokens'] / $peak * 100, 1)) : 0 }}%"></span>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-[0.8125rem] text-secondary-label" aria-hidden="true">
                <span>{{ $daily[0]['date']->translatedFormat('d M') }}</span>
                <span>{{ __('peak: :tokens', ['tokens' => $this->tokens($peak)]) }}</span>
                <span>{{ $daily[array_key_last($daily)]['date']->translatedFormat('d M') }}</span>
            </div>
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('By model')">
            <div class="-mx-3 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left" data-ai-usage-models>
                    <thead>
                        <tr class="border-b border-separator">
                            <th scope="col" class="{{ $tableHeading }}">{{ __('Model') }}</th>
                            <th scope="col" class="{{ $tableHeading }} text-right">{{ __('Calls') }}</th>
                            <th scope="col" class="{{ $tableHeading }} text-right">{{ __('Input') }}</th>
                            <th scope="col" class="{{ $tableHeading }} text-right">{{ __('From cache') }}</th>
                            <th scope="col" class="{{ $tableHeading }} text-right">{{ __('Output') }}</th>
                            <th scope="col" class="{{ $tableHeading }} text-right">{{ __('Spend') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-separator">
                        @foreach ($this->byModel() as $row)
                            <tr wire:key="ai-usage-model-{{ $row['key'] }}" data-ai-usage-model="{{ $row['key'] }}" class="text-[0.9375rem] text-label">
                                <th scope="row" class="px-3 py-2.5 font-medium">
                                    {{ $row['model'] }}
                                    <span class="block text-[0.8125rem] font-normal text-secondary-label">{{ $row['provider'] }}</span>
                                </th>
                                <td class="px-3 py-2.5 text-right tabular-nums">
                                    {{ $row['calls'] }}
                                    @if ($row['failed'] > 0)
                                        <span class="block text-[0.8125rem] text-danger">{{ __(':count failed', ['count' => $row['failed']]) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $this->tokens($row['input']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $this->tokens($row['cache_read']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $this->tokens($row['output']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">{{ $cost($row) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.settings-section>

        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ([
                ['purpose', __('By use'), $this->byPurpose()],
                ['user', __('By user'), $this->byUser()],
            ] as [$group, $title, $rows])
                <x-ui.settings-section :title="$title" wire:key="ai-usage-group-{{ $group }}">
                    <ul role="list" class="divide-y divide-separator" data-ai-usage-group="{{ $group }}">
                        @foreach ($rows as $row)
                            <li wire:key="ai-usage-{{ $group }}-{{ $row['key'] }}" data-ai-usage-row="{{ $row['label'] }}"
                                class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <p class="truncate text-[0.9375rem] font-medium text-label">{{ $row['label'] }}</p>
                                    <p class="text-[0.8125rem] text-secondary-label">
                                        {{ trans_choice(':count call|:count calls', $row['calls'], ['count' => $row['calls']]) }}
                                        · {{ $this->tokens($row['input'] + $row['output']) }} {{ __('tokens') }}
                                    </p>
                                </div>
                                <p class="shrink-0 text-[0.9375rem] text-label tabular-nums">{{ $cost($row) }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.settings-section>
            @endforeach
        </div>

        @if ($this->failures()->isNotEmpty())
            <x-ui.settings-section :title="__('Latest failures')">
                <ul role="list" class="divide-y divide-separator" data-ai-usage-failures>
                    @foreach ($this->failures() as $failure)
                        <li wire:key="ai-usage-failure-{{ $failure->id }}" class="flex flex-col gap-1 py-2.5">
                            <p class="text-[0.8125rem] text-secondary-label">
                                <x-ui.localized-date :value="$failure->created_at" />
                                · {{ \App\Enums\AiModel::labelFor($failure->model) }}
                            </p>
                            <p class="text-sm break-words text-danger">{{ $failure->error }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-ui.settings-section>
        @endif
    @endif

    <x-ui.settings-section :title="__('Prices')"
                           :description="__('What each model costs, in US dollars per million tokens, from the provider\'s price page. The spend above is estimated from them; without a cache price, cached tokens count as input.')">
        <ul role="list" class="divide-y divide-separator" data-ai-usage-prices>
            @forelse ($this->priceableModels() as $row)
                <li wire:key="ai-price-{{ $row['key'] }}" data-ai-price="{{ $row['key'] }}" class="flex flex-col gap-3 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[0.9375rem] font-medium text-label">{{ $row['label'] }}</p>
                            <p class="text-[0.8125rem] text-secondary-label">
                                {{ $row['provider'] }} ·
                                @if ($row['price'] === null)
                                    {{ __('No price') }}
                                @else
                                    {{ __('in :input · out :output', ['input' => '$'.rtrim(rtrim($row['price']->input_per_million, '0'), '.'), 'output' => '$'.rtrim(rtrim($row['price']->output_per_million, '0'), '.')]) }}
                                    @if ($row['price']->cache_read_per_million !== null)
                                        · {{ __('cache :price', ['price' => '$'.rtrim(rtrim($row['price']->cache_read_per_million, '0'), '.')]) }}
                                    @endif
                                @endif
                            </p>
                        </div>

                        @if ($editingPrice !== $row['key'])
                            <div class="flex shrink-0 items-center gap-2">
                            <x-button :text="$row['price'] === null ? __('Add price') : __('Edit')" icon="pencil-square" sm flat color="secondary"
                                      wire:click="editPrice('{{ $row['key'] }}')" />
                            @if ($row['price'] !== null)
                                <x-ui.confirm-dialog :title="__('Remove the price of :model?', ['model' => $row['label']])"
                                                     :description="__('What was used before keeps its cost in the report. What is used from now on shows its tokens only, until a price is typed again.')"
                                                     :confirm-label="__('Remove')"
                                                     danger
                                                     wire:click="removePrice('{{ $row['key'] }}')">
                                    <x-slot:trigger>
                                        <x-button :text="__('Remove')" icon="trash" sm flat color="red" data-ai-price-remove="{{ $row['key'] }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            @endif
                            </div>
                        @endif
                    </div>

                    @if ($editingPrice === $row['key'])
                        <form wire:submit="savePrice" class="flex flex-col gap-3" novalidate data-ai-price-form>
                            <div class="grid gap-3 sm:grid-cols-4">
                                <x-input type="number" step="any" min="0" :label="__('Input')" wire:model="price.input" prefix="$" required />
                                <x-input type="number" step="any" min="0" :label="__('Output')" wire:model="price.output" prefix="$" required />
                                <x-input type="number" step="any" min="0" :label="__('Cache read')" wire:model="price.cache_read" prefix="$" />
                                <x-input type="number" step="any" min="0" :label="__('Cache write')" wire:model="price.cache_write" prefix="$" />
                            </div>

                            @foreach (['input', 'output', 'cache_read', 'cache_write'] as $field)
                                @error($field)
                                    <p class="text-sm text-danger">{{ $message }}</p>
                                @enderror
                            @endforeach

                            <p class="text-[0.8125rem] text-secondary-label">{{ __('Per million tokens. Empty input and output remove the price.') }}</p>

                            <div class="flex justify-end gap-2">
                                <x-button type="button" :text="__('Cancel')" sm flat color="secondary" wire:click="cancelPrice" />
                                <x-button type="submit" :text="__('Save')" icon="check" sm loading="savePrice" />
                            </div>
                        </form>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-secondary-label">{{ __('No model yet: add a connection first.') }}</li>
            @endforelse
        </ul>
    </x-ui.settings-section>
</div>
