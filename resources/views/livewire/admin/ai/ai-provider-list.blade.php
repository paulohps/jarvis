{{--
    The remote AI provider: the connection form (the key is write-only; the
    models the key offers are ticked from the provider's main ones or typed,
    with the default among them), then the stored connections with the one in use first, each with its masked
    key, "use this one" and "test connection"; then the connection and model
    of each use of the AI.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    @if (filled($status))
        <p role="status" data-ai-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <x-ui.settings-section :title="$editingId === null ? __('Add a connection') : __('Edit the connection')"
                           :description="__('Every use of the AI without a connection of its own uses the connection in use. The key is stored encrypted and never shown again.')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-ai-form>
            <x-select.native :label="__('Provider')" wire:model.live="provider" data-ai-provider-select>
                @foreach ($this->providers as $option)
                    <option value="{{ $option->slug }}" wire:key="ai-provider-{{ $option->slug }}">{{ __($option->name) }}</option>
                @endforeach
            </x-select.native>

            <x-input :label="__('Name')" wire:model="name" autocomplete="off" required />

            <x-password :label="__('API key')"
                        wire:model="apiKey"
                        autocomplete="new-password"
                        :hint="$editingId === null ? null : __('Leave empty to keep the stored key.')" />

            @if ($this->requiresBaseUrl())
                <x-input type="url" :label="__('Base URL')" wire:model="baseUrl" placeholder="https://" required />
            @endif

            <fieldset class="flex flex-col gap-3" data-ai-models>
                <legend class="mb-1 text-sm font-medium text-label">{{ __('Models this key offers') }}</legend>

                @if ($this->providerModels() !== [])
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($this->providerModels() as $option)
                            <x-checkbox :label="$option->label()" wire:model.live="models" value="{{ $option->value }}"
                                        wire:key="ai-model-{{ $provider }}-{{ $option->value }}" data-ai-model="{{ $option->value }}" />
                        @endforeach
                    </div>
                @endif

                <x-textarea :label="__('Other models')" wire:model.live.debounce.500ms="otherModels" rows="2"
                            :hint="__('Model ids as the provider names them, one per line.')" data-ai-other-models />

                @error('models')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror

                <x-select.native :label="__('Default model')" wire:model="model" data-ai-default-model>
                    <option value="">{{ __('The first one') }}</option>
                    @foreach ($this->pickedModels() as $option)
                        <option value="{{ $option }}" wire:key="ai-default-{{ $option }}">{{ \App\Enums\AiModel::labelFor($option) }}</option>
                    @endforeach
                </x-select.native>
            </fieldset>

            <div class="flex justify-end gap-2">
                @if ($editingId !== null)
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" wire:click="cancelEditing" />
                @endif

                <x-button type="submit" :text="__('Save the connection')" icon="check" loading="save" />
            </div>
        </form>
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('Connections')">
        @if ($this->connections->isEmpty())
            <x-ui.empty-state icon="sparkles"
                              :title="__('No AI provider yet')"
                              :description="__('Without a connection in use, remote voice commands answer “AI provider not configured”.')" />
        @else
            <ul role="list" class="divide-y divide-separator">
                @foreach ($this->connections as $connection)
                    <li wire:key="ai-connection-{{ $connection->id }}"
                        data-ai-connection="{{ $connection->friendly_id }}"
                        data-ai-connection-active="{{ $connection->is_active ? 'true' : 'false' }}"
                        class="flex flex-col gap-3 py-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-[0.9375rem] font-medium text-label">{{ $connection->name }}</p>

                                    @if ($connection->is_active)
                                        <x-ui.status-badge status="active" :label="__('In use')" />
                                    @endif
                                </div>

                                <p class="text-[0.8125rem] text-secondary-label">
                                    {{ __($connection->provider->name) }} · {{ \App\Enums\AiModel::labelFor($connection->model) }}
                                    @if (count($connection->models) > 1)
                                        {{ __('(+:count models)', ['count' => count($connection->models) - 1]) }}
                                    @endif
                                    @if ($connection->maskedApiKey() !== null)
                                        · <span data-ai-key-mask>{{ $connection->maskedApiKey() }}</span>
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2" data-ai-connection-actions>
                                <x-button :text="__('Test')" icon="bolt" sm flat color="secondary"
                                          wire:click="test({{ $connection->id }})" loading="test({{ $connection->id }})" />
                                <x-button :text="__('Edit')" icon="pencil-square" sm flat color="secondary"
                                          wire:click="edit({{ $connection->id }})" />

                                @unless ($connection->is_active)
                                    <x-button :text="__('Use this one')" icon="check-circle" sm flat
                                              wire:click="activate({{ $connection->id }})" loading="activate({{ $connection->id }})" />
                                @endunless

                                <x-ui.confirm-dialog :title="__('Remove :name?', ['name' => $connection->name])"
                                                     :description="$connection->is_active
                                                         ? __('It is the connection in use: the AI stops until you put another in use. The uses with their own connection keep it. Its usage history stays.')
                                                         : __('The uses of the AI that had it go back to the connection in use. Its usage history stays.')"
                                                     :confirm-label="__('Remove')"
                                                     danger
                                                     wire:click="delete({{ $connection->id }})">
                                    <x-slot:trigger>
                                        <x-button :text="__('Remove')" icon="trash" sm flat color="red" data-ai-connection-delete="{{ $connection->friendly_id }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            </div>
                        </div>

                        @isset($testResults[$connection->id])
                            @php
                                $result = $testResults[$connection->id];
                            @endphp

                            <p role="status"
                               data-ai-test-result="{{ $result['successful'] ? 'success' : 'failure' }}"
                                @class([
                                    'rounded-control px-4 py-2 text-sm break-words',
                                    'bg-success/10 text-success' => $result['successful'],
                                    'bg-danger/10 text-danger' => ! $result['successful'],
                                ])>
                                {{ $result['successful'] ? $result['message'] : __('The provider answered with an error: :message', ['message' => $result['message']]) }}
                            </p>
                        @endisset
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('AI per use')"
                           :description="__('Pick the connection, then one of the models it offers, for each use: a cheaper or a stronger model where it fits. Left on the connection in use, a use follows it.')">
        <ul role="list" class="divide-y divide-separator">
            @foreach ($this->purposes as $purpose)
                <li wire:key="ai-purpose-{{ $purpose->slug }}" data-ai-purpose="{{ $purpose->slug }}" class="flex flex-col gap-3 py-3">
                    <p class="text-[0.9375rem] font-medium text-label">{{ __($purpose->name) }}</p>

                    <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <x-select.native :label="__('Connection')" wire:model.live="assignments.{{ $purpose->slug }}.connection">
                            <option value="">{{ __('The connection in use') }}</option>
                            @foreach ($this->connections as $connection)
                                <option value="{{ $connection->friendly_id }}" wire:key="ai-purpose-{{ $purpose->slug }}-{{ $connection->id }}">{{ $connection->name }} · {{ __($connection->provider->name) }}</option>
                            @endforeach
                        </x-select.native>

                        @php
                            $pickedConnection = $assignments[$purpose->slug]['connection'] ?? '';
                        @endphp

                        <x-select.native :label="__('Model')" wire:model="assignments.{{ $purpose->slug }}.model"
                                         :disabled="$pickedConnection === ''" data-ai-purpose-model>
                            <option value="">{{ __('The connection\'s default') }}</option>
                            @foreach ($pickedConnection === '' ? [] : $this->connectionModels($pickedConnection) as $option)
                                <option value="{{ $option }}" wire:key="ai-purpose-{{ $purpose->slug }}-model-{{ $option }}">{{ \App\Enums\AiModel::labelFor($option) }}</option>
                            @endforeach
                        </x-select.native>

                        <x-button :text="__('Save')" icon="check" sm wire:click="savePurpose('{{ $purpose->slug }}')"
                                  loading="savePurpose('{{ $purpose->slug }}')" />
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.settings-section>
</div>
