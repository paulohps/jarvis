{{--
    The texts of one notification type: a tab per channel and language, the
    editor of the open tab beside its preview, and one "Save" for all tabs.
--}}
@php
    $current = $templates[$channel][$locale] ?? null;
    $field = "templates.{$channel}.{$locale}";
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.notifications.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Notifications') }}
        </a>

        <x-button :text="__('Save')" icon="check" wire:click="save" loading="save" data-save-templates />
    </div>

    <p class="text-[0.9375rem] text-secondary-label">{{ $this->notificationClass::description() }}</p>

    @if ($statusMessage !== null)
        <p role="status" class="rounded-control bg-fill px-4 py-2 text-[0.875rem] text-label">{{ $statusMessage }}</p>
    @endif

    @if ($errors->isNotEmpty())
        <p role="alert" class="rounded-control bg-fill px-4 py-2 text-[0.875rem] text-danger">{{ __('The texts were not saved. Fix the problems marked in the tabs.') }}</p>
    @endif

    <nav aria-label="{{ __('Channels and languages') }}" class="flex flex-wrap gap-2">
        @foreach ($this->channels as $channelSlug => $channelLabel)
            @foreach ($this->locales() as $localeSlug => $localeName)
                @php
                    $isOpen = $channelSlug === $channel && $localeSlug === $locale;
                    $hasProblems = collect($errors->keys())->contains(fn (string $key): bool => str_starts_with($key, "templates.{$channelSlug}.{$localeSlug}."));
                @endphp

                <button type="button"
                        wire:key="tab-{{ $channelSlug }}-{{ $localeSlug }}"
                        wire:click="selectTab('{{ $channelSlug }}', '{{ $localeSlug }}')"
                        data-tab="{{ $channelSlug }}.{{ $localeSlug }}"
                        @if ($isOpen) aria-current="true" @endif
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[0.8125rem] font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                            'bg-tint-fill text-on-tint' => $isOpen,
                            'bg-fill text-label hover:bg-fill-pressed' => ! $isOpen,
                        ])>
                    {{ $channelLabel }} · {{ $localeName }}

                    @if ($hasProblems)
                        <x-icon name="exclamation-circle" class="size-4 text-danger" aria-hidden="true" />
                        <span class="sr-only">{{ __('Has problems') }}</span>
                    @elseif ($this->isMissing($channelSlug, $localeSlug))
                        <span class="text-[0.75rem] opacity-80">({{ __('no text') }})</span>
                    @endif
                </button>
            @endforeach
        @endforeach
    </nav>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <x-ui.settings-section :title="$this->channels[$channel].' · '.$this->locales()[$locale]"
                               :description="$this->storedTemplate?->updatedBy !== null
                                   ? __('Last edited by :name on :date.', ['name' => $this->storedTemplate->updatedBy->name, 'date' => $this->storedTemplate->updated_at?->isoFormat('LLL')])
                                   : __('Default text, never edited.')">
            @if ($current === null)
                <div class="flex flex-col items-start gap-3">
                    <p class="text-[0.9375rem] text-secondary-label">{{ __('There is no text in this language: its readers get the English text.') }}</p>
                    <x-button :text="__('Write the translation')" icon="language" sm outline wire:click="writeTranslation" />
                </div>
            @else
                <x-ui.template-editor :schema="$this->schema"
                                      :name="$field.'.title'"
                                      :label="__('Title')"
                                      :rows="2"
                                      mode="inline"
                                      wire:model.live.debounce.500ms="{{ $field }}.title"
                                      wire:key="editor-{{ $channel }}-{{ $locale }}-title" />

                <x-ui.template-editor :schema="$this->schema"
                                      :name="$field.'.body'"
                                      :label="__('Body')"
                                      :rows="8"
                                      mode="rich"
                                      wire:model.live.debounce.500ms="{{ $field }}.body"
                                      wire:key="editor-{{ $channel }}-{{ $locale }}-body" />

                <div class="flex flex-col gap-3">
                    <p class="text-[0.8125rem] font-medium text-secondary-label">{{ __('Buttons') }}</p>

                    @forelse ($current['actions'] as $index => $action)
                        <div wire:key="action-{{ $channel }}-{{ $locale }}-{{ $index }}" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <div class="sm:w-56">
                                <x-select.native :label="__('Link')"
                                                 :options="collect($this->links)->map(fn ($label, $key) => ['label' => $label, 'value' => $key])->values()->all()"
                                                 select="label:label|value:value"
                                                 wire:model.live="{{ $field }}.actions.{{ $index }}.link_key" />
                            </div>

                            <div class="flex-1">
                                <x-input :label="__('Label')"
                                         :hint="__('Empty uses the link\'s own label.')"
                                         wire:model.live.debounce.500ms="{{ $field }}.actions.{{ $index }}.label" />
                            </div>

                            <x-button.circle icon="trash" color="red" flat sm class="sm:mt-6"
                                             :aria-label="__('Remove the button')"
                                             wire:click="removeAction({{ $index }})" />
                        </div>
                    @empty
                        <p class="text-[0.875rem] text-secondary-label">{{ __('No buttons.') }}</p>
                    @endforelse

                    @if ($this->links !== [])
                        <div>
                            <x-button :text="__('Add a button')" icon="plus" sm flat color="secondary" wire:click="addAction" />
                        </div>
                    @endif
                </div>
            @endif
        </x-ui.settings-section>

        <aside class="flex flex-col gap-4" aria-label="{{ __('Preview') }}">
            @if ($this->titlePreview !== null)
                <x-ui.template-preview :preview="$this->titlePreview" :label="__('Title preview')" />
            @endif

            @if ($this->bodyPreview !== null)
                <x-ui.template-preview :preview="$this->bodyPreview" :label="__('Body preview')" />
            @endif
        </aside>
    </div>
</div>
