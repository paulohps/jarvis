{{--
    The visual editor of a `{% %}` template (App\View\Components\Ui\TemplateEditor;
    script in resources/js/components/template-editor). Other code can insert into it by
    dispatching `template-editor-insert` on window with { name, kind: 'variable' | 'if' | 'for',
    path }; it dispatches `template-editor-focus` with { name } when it takes the focus. It is
    a convenience, not a boundary: whatever it writes is validated and sanitized again on the
    server.
--}}
@php
    $errorKey = $name ?? $attributes->wire('model')->value();
    $messages = filled($errorKey) && isset($errors) ? $errors->get($errorKey) : [];
    $id = $fieldId($errorKey);
    $editorConfig = $config($errorKey);
    $placeholder = $attributes->get('placeholder');
    $hasCollections = $editorConfig['collections'] !== [];

    $toolClass = 'inline-flex h-7 min-w-7 items-center justify-center rounded-md px-1.5 text-[0.875rem] text-secondary-label hover:bg-fill hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:opacity-40 disabled:hover:bg-transparent';
    $menuClass = 'inline-flex h-7 items-center gap-1 rounded-md px-2 text-[0.75rem] font-medium text-label hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:opacity-40 disabled:hover:bg-transparent';
    $panelClass = 'glass-thick glass-edge absolute left-0 z-30 mt-1 max-h-80 w-72 overflow-y-auto rounded-control py-1';
    $optionClass = 'block w-full px-3 py-1.5 text-left hover:bg-fill focus:bg-fill focus:outline-none';
    $fieldClass = 'block w-full rounded-md border-0 bg-fill px-2 py-1 text-[0.875rem] text-label focus:ring-2 focus:ring-tint focus:outline-none';
@endphp

<div x-data="templateEditor(@js($editorConfig))"
     x-modelable="value"
     x-on:template-editor-insert.window="receive($event.detail)"
     data-template-editor="{{ $editorConfig['name'] }}"
     data-template-editor-mode="{{ $mode }}"
     {{ $attributes->except('placeholder')->class('flex flex-col gap-2') }}>
    @if (filled($label))
        <span id="{{ $id }}-label" class="text-[0.8125rem] font-medium text-secondary-label">{{ $label }}</span>
    @endif

    @if (filled($name))
        <input type="hidden" name="{{ $name }}" x-bind:value="value" value="{{ $value }}">
    @endif

    <div @class([
        'rounded-control bg-fill focus-within:ring-2',
        'ring-2 ring-danger focus-within:ring-danger' => $messages !== [],
        'focus-within:ring-tint' => $messages === [],
    ])>
        {{--
            Everything in here is drawn by the script; a Livewire morph would wipe it. So its
            `aria-invalid` is the one of the first render: the error list below stays current.
        --}}
        <div wire:ignore>
            <div x-ref="toolbar" class="flex flex-wrap items-center gap-1 rounded-t-control border-b border-separator px-2 py-1">
                @if ($isRich())
                    <button type="button" class="{{ $toolClass }} font-bold" title="{{ __('Bold') }}" aria-label="{{ __('Bold') }}"
                            x-bind:disabled="mode !== 'visual'" x-on:mousedown.prevent x-on:click="format('bold')">B</button>

                    <button type="button" class="{{ $toolClass }} font-serif italic" title="{{ __('Italic') }}" aria-label="{{ __('Italic') }}"
                            x-bind:disabled="mode !== 'visual'" x-on:mousedown.prevent x-on:click="format('italic')">I</button>

                    <button type="button" class="{{ $toolClass }} underline" title="{{ __('Underline') }}" aria-label="{{ __('Underline') }}"
                            x-bind:disabled="mode !== 'visual'" x-on:mousedown.prevent x-on:click="format('underline')">U</button>

                    <span class="mx-1 h-5 w-px bg-separator" aria-hidden="true"></span>

                    <button type="button" class="{{ $toolClass }}" title="{{ __('Bulleted list') }}" aria-label="{{ __('Bulleted list') }}"
                            x-bind:disabled="mode !== 'visual'" x-on:mousedown.prevent x-on:click="format('insertUnorderedList')">
                        <x-icon name="list-bullet" class="size-4" aria-hidden="true" />
                    </button>

                    <button type="button" class="{{ $toolClass }}" title="{{ __('Numbered list') }}" aria-label="{{ __('Numbered list') }}"
                            x-bind:disabled="mode !== 'visual'" x-on:mousedown.prevent x-on:click="format('insertOrderedList')">
                        <x-icon name="numbered-list" class="size-4" aria-hidden="true" />
                    </button>

                    <span class="mx-1 h-5 w-px bg-separator" aria-hidden="true"></span>
                @endif

                <div class="relative" x-on:click.outside="menu === 'variable' && closeMenu()">
                    <button type="button" class="{{ $menuClass }}" x-on:mousedown.prevent x-on:click="toggleMenu('variable')"
                            aria-haspopup="true" x-bind:aria-expanded="menu === 'variable'" data-template-editor-menu="variable">
                        <span class="text-tint" aria-hidden="true">+</span> {{ __('Variable') }}
                    </button>

                    <div x-cloak x-show="menu === 'variable'" class="{{ $panelClass }}">
                        <template x-for="option in variableOptions()" :key="option.path">
                            <button type="button" class="{{ $optionClass }}" x-on:mousedown.prevent x-on:click="insertVariable(option.path)">
                                <span class="block text-[0.875rem] text-label" x-text="option.description"></span>
                                <span class="block font-mono text-[0.75rem] text-secondary-label" x-text="option.label"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="relative" x-on:click.outside="menu === 'if' && closeMenu()">
                    <button type="button" class="{{ $menuClass }}" x-on:mousedown.prevent x-on:click="toggleMenu('if')"
                            aria-haspopup="true" x-bind:aria-expanded="menu === 'if'" data-template-editor-menu="if">
                        <span class="text-warning" aria-hidden="true">+</span> {{ __('Condition') }}
                    </button>

                    <div x-cloak x-show="menu === 'if'" class="{{ $panelClass }}">
                        <template x-if="condition === null">
                            <div>
                                <p class="px-3 py-1 text-[0.75rem] text-secondary-label">{{ __('Show a part only when…') }}</p>

                                <template x-for="option in conditionOptions()" :key="option.path">
                                    <button type="button" class="{{ $optionClass }}" x-on:mousedown.prevent x-on:click="chooseCondition(option.path)">
                                        <span class="block text-[0.875rem] text-label" x-text="option.description"></span>
                                        <span class="block font-mono text-[0.75rem] text-secondary-label" x-text="option.label"></span>
                                    </button>
                                </template>
                            </div>
                        </template>

                        {{-- Real inputs, outside the editable area, so typing here never lands in the text. --}}
                        <template x-if="condition !== null">
                            <div class="flex flex-col gap-2 px-3 py-2">
                                <p class="text-[0.75rem] text-secondary-label">
                                    {{ __('Show only if') }} <strong class="font-mono text-label" x-text="condition.label"></strong>
                                </p>

                                <select class="{{ $fieldClass }} pr-8" x-model="condition.operator" aria-label="{{ __('How to compare') }}">
                                    <option value="">{{ __('has a value') }}</option>
                                    <option value="==">{{ __('is') }}</option>
                                    <option value="!=">{{ __('is not') }}</option>
                                </select>

                                <template x-if="condition.operator !== '' && condition.values.length > 0">
                                    <select class="{{ $fieldClass }} pr-8" x-model="condition.value" aria-label="{{ __('Value') }}">
                                        <option value="">{{ __('Choose a value…') }}</option>

                                        <template x-for="choice in conditionValues()" :key="choice">
                                            <option x-bind:value="choice" x-bind:selected="choice === condition.value" x-text="choice"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="condition.operator !== '' && condition.values.length === 0">
                                    <input type="text" class="{{ $fieldClass }}" x-model="condition.value"
                                           placeholder="{{ __('Value') }}" aria-label="{{ __('Value') }}"
                                           x-on:keydown.enter.prevent="applyCondition()">
                                </template>

                                <div class="flex items-center justify-between pt-1">
                                    <button type="button" class="text-[0.75rem] text-secondary-label hover:text-label"
                                            x-on:click="condition.editing ? closeMenu() : (condition = null)"
                                            x-text="condition.editing ? @js(__('Cancel')) : @js(__('Back'))"></button>

                                    <button type="button"
                                            class="rounded-md bg-tint-fill px-2 py-1 text-[0.75rem] font-medium text-on-tint hover:bg-tint-fill-pressed disabled:opacity-40"
                                            x-bind:disabled="! conditionIsComplete()"
                                            x-on:click="applyCondition()"
                                            x-text="condition.editing ? @js(__('Update condition')) : @js(__('Add condition'))"></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                @if ($hasCollections)
                    <div class="relative" x-on:click.outside="menu === 'for' && closeMenu()">
                        <button type="button" class="{{ $menuClass }}" x-on:mousedown.prevent
                                x-bind:disabled="mode === 'visual' && scope.length > 0"
                                x-bind:title="mode === 'visual' && scope.length > 0 ? @js(__('A repeat cannot go inside another one.')) : ''"
                                x-on:click="toggleMenu('for')"
                                aria-haspopup="true" x-bind:aria-expanded="menu === 'for'" data-template-editor-menu="for">
                            <span class="text-success" aria-hidden="true">+</span> {{ __('Repeat') }}
                        </button>

                        <div x-cloak x-show="menu === 'for'" class="{{ $panelClass }}">
                            <p class="px-3 py-1 text-[0.75rem] text-secondary-label">{{ __('Repeat a part for each item in…') }}</p>

                            <template x-for="option in loopOptions()" :key="option.path">
                                <button type="button" class="{{ $optionClass }}" x-on:mousedown.prevent x-on:click="insertBlock('for', option.path)">
                                    <span class="block text-[0.875rem] text-label" x-text="option.description"></span>
                                    <span class="block font-mono text-[0.75rem] text-secondary-label" x-text="option.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                @endif

                <button type="button" class="ms-auto {{ $menuClass }}" x-on:mousedown.prevent x-on:click="toggleMode()"
                        data-template-editor-toggle
                        x-text="mode === 'visual' ? @js(__('Edit as code')) : @js(__('Visual editor'))">{{ __('Edit as code') }}</button>
            </div>

            <div class="relative">
                <div x-ref="surface"
                     id="{{ $id }}"
                     x-show="mode === 'visual'"
                     contenteditable="true"
                     role="textbox"
                     spellcheck="true"
                     aria-multiline="{{ $isInline() ? 'false' : 'true' }}"
                     @if (filled($label)) aria-labelledby="{{ $id }}-label" @else aria-label="{{ $errorKey ?? __('Template') }}" @endif
                     aria-describedby="{{ $id }}-errors" @if ($messages !== []) aria-invalid="true" @endif
                     @class([
                         'px-3 py-2 text-[0.9375rem] text-label focus:outline-none',
                         'whitespace-pre-wrap break-words' => ! $isRich(),
                         'max-h-[28rem] min-h-32 overflow-y-auto [&_ol]:my-1 [&_ol]:list-decimal [&_ol]:ps-5 [&_p]:my-1 [&_ul]:my-1 [&_ul]:list-disc [&_ul]:ps-5' => $isRich(),
                         'max-h-[28rem] min-h-24 overflow-y-auto' => $mode === \App\View\Components\Ui\TemplateEditor::MODE_PLAIN,
                     ])
                     x-on:input="scheduleSync()"
                     x-on:focus="onFocus()"
                     x-on:blur="sync()"
                     x-on:paste.prevent="onPaste($event)"
                     x-on:drop.prevent
                     x-on:click="onClick($event)"
                     x-on:keydown="onKeydown($event)"></div>

                @if (filled($placeholder))
                    <p x-cloak x-show="mode === 'visual' && isEmpty"
                       class="pointer-events-none absolute start-3 top-2 text-[0.9375rem] text-secondary-label">{{ $placeholder }}</p>
                @endif

                <textarea x-ref="code"
                          x-cloak
                          x-show="mode === 'code'"
                          x-model.debounce.400ms="value"
                          x-on:focus="$dispatch('template-editor-focus', { name: config.name })"
                          rows="{{ $rows }}"
                          spellcheck="false"
                          @if (filled($label)) aria-labelledby="{{ $id }}-label" @else aria-label="{{ $errorKey ?? __('Template') }}" @endif
                          aria-describedby="{{ $id }}-errors" @if ($messages !== []) aria-invalid="true" @endif
                          class="block min-h-16 w-full resize-y rounded-b-control border-0 bg-transparent px-3 py-2 font-mono text-[0.8125rem] text-label focus:ring-0"></textarea>
            </div>

            <p x-cloak x-show="unsupported"
               class="rounded-b-control border-t border-separator px-3 py-2 text-[0.75rem] text-warning"
               x-text="@js(__('This text has :what, which the visual editor cannot show yet. Keep editing it as code, or simplify it and switch back.')).replace(':what', unsupported)"></p>
        </div>
    </div>

    {{-- Always there, so the editor (which Livewire leaves alone) can point to it. --}}
    <ul id="{{ $id }}-errors" @class(['flex flex-col gap-0.5 text-[0.8125rem] text-danger', 'hidden' => $messages === []]) role="alert">
        @foreach ($messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
</div>
