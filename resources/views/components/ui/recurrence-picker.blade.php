@php($frequency = \App\Models\RecurrenceFrequency::class)
<fieldset data-recurrence-picker {{ $attributes->class('flex flex-col gap-4') }}>
    <legend class="mb-2 text-sm font-medium text-label">{{ __('Repeat') }}</legend>

    <x-ui.segmented-control :name="$model('frequency')"
                            wire:model.live="{{ $model('frequency') }}"
                            :label="__('Repeat')"
                            :value="$form->frequency"
                            :options="$form->frequencyOptions()"
                            data-recurrence-frequency />

    @if ($isOnce())
        <x-input type="datetime-local"
                 :label="__('Date and time')"
                 wire:model.live="{{ $model('starts_at') }}"
                 data-recurrence-once />
    @else
        @if ($is($frequency::WEEKLY))
            <div class="flex flex-col gap-2" data-recurrence-weekdays>
                <p class="text-sm font-medium text-label">{{ __('Repeats on') }}</p>

                <div role="group" aria-label="{{ __('Days of the week') }}" class="flex flex-wrap gap-2">
                    @foreach ($form->weekdayOptions() as $slug => $label)
                        <label wire:key="{{ $model('weekdays') }}-{{ $slug }}"
                               class="flex min-h-9 min-w-11 cursor-pointer items-center justify-center rounded-full bg-fill px-3 text-sm font-medium text-label transition has-[:checked]:bg-tint-fill has-[:checked]:text-on-tint has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-tint">
                            <input type="checkbox" class="sr-only" value="{{ $slug }}" wire:model.live="{{ $model('weekdays') }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                @error($model('weekdays'))
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
            </div>
        @endif

        @if ($is($frequency::MONTHLY))
            <div class="flex flex-col gap-3" data-recurrence-monthly>
                @unless ($form->is_last_day_of_month)
                    <x-input type="number" min="1" max="31"
                             :label="__('Day of the month')"
                             :hint="__('Shorter months run on their last day.')"
                             wire:model.live="{{ $model('day_of_month') }}" />
                @endunless

                <x-checkbox :label="__('Last day of the month')" wire:model.live="{{ $model('is_last_day_of_month') }}" />
            </div>
        @endif

        @if ($is($frequency::CUSTOM_CRON))
            <x-input :label="__('Cron expression')"
                     :hint="__('Minute, hour, day of the month, month and day of the week, e.g. 0 9 * * 1-5.')"
                     class="font-mono"
                     wire:model.live.debounce.500ms="{{ $model('cron_expression') }}"
                     data-recurrence-cron />
        @else
            <x-input type="time" :label="__('Time')" wire:model.live="{{ $model('time_of_day') }}" />
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <x-input type="datetime-local" :label="__('Starts')" wire:model.live="{{ $model('starts_at') }}" />
            <x-input type="datetime-local" :label="__('Ends (optional)')" wire:model.live="{{ $model('ends_at') }}" />
        </div>
    @endif

    <div class="flex flex-col gap-1 rounded-control bg-fill px-4 py-3 text-sm" data-recurrence-preview>
        <p class="font-medium text-label">{{ $isOnce() ? __('Runs on') : __('Next runs') }}</p>

        @forelse ($preview() as $run)
            <x-ui.localized-date :value="$run" :timezone="$form->timezone" class="text-secondary-label" data-recurrence-preview-run />
        @empty
            <p class="text-secondary-label">{{ __('No upcoming run.') }}</p>
        @endforelse
    </div>

    <p class="text-xs text-secondary-label" data-recurrence-timezone>{{ __('Times in :timezone.', ['timezone' => $form->timezone]) }}</p>
</fieldset>
