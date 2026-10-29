{{--
    The AI's side panel of an open chat (DraftAssistant): a conversation
    with the AI about what to write, each message it writes with "Edit"
    (the bubble turns into the field, holding it) and "Use message", and
    the past conversations of the chat.
--}}
@php
    $messages = $this->conversation?->messages ?? collect();
    $lastWritten = $messages->last(fn ($turn) => $turn->isFromAssistant());
    $editsSent = $this->sentMessage?->canEdit() ?? false;
@endphp

<div data-chat-draft-assistant="{{ $chatId }}">
    <x-slide wire="show" id="draft-assistant" size="2xl" paddingless>
        <x-slot:title>
            <span class="flex min-w-0 items-center gap-2">
                <x-icon name="sparkles" solid class="size-5 shrink-0 text-tint" aria-hidden="true" />
                <span class="truncate text-label" data-chat-draft-title>
                    {{ $showHistory ? __('Conversations with the AI') : ($this->conversation?->title ?? __('Write with AI')) }}
                </span>
            </span>
        </x-slot:title>

        <div class="flex h-full flex-col bg-chat-wallpaper text-chat-text">
            <div class="flex items-center gap-1 border-b border-separator bg-chat-panel px-3 py-1.5">
                <x-whatsapp.chat-action size="md" :icon="$showHistory ? 'chat-bubble-left-right' : 'clock'" :active="$showHistory"
                                        :title="$showHistory ? __('Back to the conversation') : __('Past conversations')"
                                        wire:click="$toggle('showHistory')" data-chat-draft-history-toggle />
                <x-whatsapp.chat-action size="md" icon="pencil-square" :title="__('New conversation')" wire:click="startNew" data-chat-draft-new />
                <span class="ml-auto truncate text-xs text-chat-meta">{{ $this->chat->displayName() }}</span>
            </div>

            @if ($showHistory)
                <ul class="flex-1 divide-y divide-separator overflow-y-auto bg-chat-input" data-chat-draft-history>
                    @forelse ($this->conversations as $past)
                        <li class="group flex items-center gap-2 px-4 py-3 hover:bg-fill" wire:key="draft-conversation-{{ $past->friendly_id }}">
                            <button type="button" class="min-w-0 flex-1 text-left focus:outline-none" wire:click="openConversation('{{ $past->friendly_id }}')"
                                    data-chat-draft-conversation="{{ $past->friendly_id }}">
                                <span @class(['block truncate text-sm font-medium', 'text-tint' => $past->friendly_id === $conversationId, 'text-chat-text' => $past->friendly_id !== $conversationId])>{{ $past->title }}</span>
                                <span class="block text-xs text-chat-meta">{{ $past->updated_at?->diffForHumans() }}</span>
                            </button>
                            <x-whatsapp.chat-action size="md" icon="trash" :title="__('Delete')" class="opacity-0 group-hover:opacity-100 focus:opacity-100 pointer-coarse:opacity-100"
                                                    wire:click="deleteConversation('{{ $past->friendly_id }}')" wire:confirm="{{ __('Delete this conversation?') }}"
                                                    data-chat-draft-delete="{{ $past->friendly_id }}" />
                        </li>
                    @empty
                        <li class="px-4 py-10 text-center text-sm text-chat-meta">{{ __('No conversations with the AI in this chat yet.') }}</li>
                    @endforelse
                </ul>
            @else
                {{-- Reversed, so that it stays at its end as messages come. --}}
                <div class="flex flex-1 flex-col-reverse overflow-y-auto px-4 py-3" data-chat-draft-messages>
                    <div class="flex flex-col gap-2">
                        @if ($messages->isEmpty())
                            <div class="mx-auto my-6 max-w-xs rounded-card bg-chat-bubble-in px-4 py-3 text-center text-sm text-chat-meta shadow-soft" data-chat-draft-empty>
                                {{ __('Say what the message must say, or ask the AI to answer. Then ask for changes, edit it yourself, and use the one you like.') }}
                            </div>
                        @endif

                        @foreach ($messages as $turn)
                            <div wire:key="draft-turn-{{ $turn->friendly_id }}" @class(['flex', 'justify-end' => ! $turn->isFromAssistant()])
                                 data-chat-draft-turn="{{ $turn->role }}">
                                <div x-data="{ editing: false }" @class([
                                    'relative max-w-[85%] rounded-[0.5rem] px-3 pt-1.5 pb-2 text-[0.9375rem] leading-6 break-words shadow-soft',
                                    'rounded-tl-none bg-chat-bubble-in' => $turn->isFromAssistant(),
                                    'rounded-tr-none bg-chat-bubble-out' => ! $turn->isFromAssistant(),
                                ]) x-bind:class="editing && 'w-full max-w-full! ring-2 ring-tint/50'">
                                    <div x-show="! editing" class="whitespace-pre-wrap" data-chat-draft-body>{{ \App\Support\Whatsapp\MessageFormat::toHtml($turn->body, $this->mentionNames) }}</div>

                                    @if ($turn->isFromAssistant())
                                        {{-- Editing turns the bubble into the field, holding the message as it is. --}}
                                        <template x-if="editing">
                                            <div x-data="whatsappEditor({ initial: @js($turn->body), group: @js($this->chat->isGroup()), send: (editor) => $wire.saveEditing(@js($turn->friendly_id), editor.value).then((saved) => { if (saved) editing = false }) })"
                                                 class="relative" data-chat-draft-editing>
                                                <x-whatsapp.mention-list below />
                                                <div x-ref="field" role="textbox" aria-multiline="true" aria-label="{{ __('Edit the message') }}" contenteditable="true"
                                                     x-bind:data-empty="empty.toString()" data-placeholder="{{ __('Type a message') }}"
                                                     x-init="$nextTick(() => caretToEnd())"
                                                     x-on:input="onInput()" x-on:keydown="onKeydown($event)" x-on:keydown.escape.stop="editing = false"
                                                     x-on:paste="onPaste($event)" x-on:keyup="track()" x-on:click="track()"
                                                     class="chat-field max-h-96 min-h-6 overflow-y-auto break-words whitespace-pre-wrap text-chat-text outline-none"
                                                     data-chat-draft-edit-field></div>
                                                <x-whatsapp.format-balloon />
                                                <div class="mt-2 flex items-center justify-end gap-3 text-xs">
                                                    <span class="mr-auto text-chat-meta">{{ __('Enter saves, Esc cancels') }}</span>
                                                    <button type="button" x-on:click="editing = false" class="font-medium text-chat-meta hover:text-chat-text">{{ __('Cancel') }}</button>
                                                    <button type="button" x-on:click="submit()" class="rounded-full bg-chat-accent px-3 py-1 font-medium text-on-tint hover:opacity-90" data-chat-draft-save>{{ __('Save') }}</button>
                                                </div>
                                            </div>
                                        </template>

                                        <div x-show="! editing" class="mt-1.5 flex flex-wrap items-center justify-end gap-x-3 gap-y-1 text-xs">
                                            @if ($turn->edited_at !== null)
                                                <span class="mr-auto text-chat-meta italic" data-chat-draft-edited>{{ __('Edited by you') }}</span>
                                            @endif
                                            <button type="button" x-on:click="editing = true"
                                                    class="flex items-center gap-1 font-medium text-chat-meta hover:text-chat-text focus:outline-none focus-visible:underline"
                                                    data-chat-draft-edit="{{ $turn->friendly_id }}">
                                                <x-icon name="pencil" class="size-4" aria-hidden="true" />{{ __('Edit') }}
                                            </button>
                                            <button type="button" wire:click="useMessage('{{ $turn->friendly_id }}')"
                                                    @class([
                                                        'flex items-center gap-1 rounded-full px-3 py-1 font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                                                        'bg-chat-accent text-on-tint hover:opacity-90' => $turn->is($lastWritten),
                                                        'text-chat-link hover:underline' => ! $turn->is($lastWritten),
                                                    ])
                                                    data-chat-draft-use="{{ $turn->friendly_id }}">
                                                <x-icon :name="$editsSent ? 'pencil-square' : 'arrow-down-on-square'" class="size-4" aria-hidden="true" />{{ $editsSent ? __('Edit the sent message') : __('Use message') }}
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        <div wire:loading.flex wire:target="ask" class="flex-col gap-2" data-chat-draft-pending>
                            <div class="flex justify-end">
                                <div class="max-w-[85%] rounded-[0.5rem] rounded-tr-none bg-chat-bubble-out px-3 py-1.5 text-[0.9375rem] leading-6 break-words whitespace-pre-wrap opacity-70 shadow-soft"
                                     x-text="$wire.request"></div>
                            </div>
                            <div class="flex">
                                <div class="flex items-center gap-1 rounded-[0.5rem] rounded-tl-none bg-chat-bubble-in px-4 py-3 shadow-soft" role="status" aria-label="{{ __('Writing…') }}">
                                    <span class="size-2 animate-bounce rounded-full bg-chat-meta [animation-delay:-0.3s]"></span>
                                    <span class="size-2 animate-bounce rounded-full bg-chat-meta [animation-delay:-0.15s]"></span>
                                    <span class="size-2 animate-bounce rounded-full bg-chat-meta"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-separator bg-chat-panel px-3 pt-2 pb-3"
                     x-data="whatsappEditor({ model: 'request', group: @js($this->chat->isGroup()), send: 'ask' })" data-chat-draft-form>
                    @if ($this->sentMessage !== null)
                        <p class="flex items-start gap-2 rounded-control bg-chat-input px-3 py-2 text-xs text-chat-meta" data-chat-draft-sent>
                            <x-icon name="paper-airplane" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>
                                {{ $editsSent
                                    ? __('You sent a message from this conversation. Ask for a change and “Edit the sent message” opens the edit with it.')
                                    : __('You sent a message from this conversation. WhatsApp no longer lets it be edited: the next one goes to the composer.') }}
                            </span>
                        </p>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <label class="flex cursor-pointer items-center gap-2 text-chat-meta">
                            <x-toggle wire:model.live="readsChat" sm data-chat-draft-reads-chat />
                            {{ __('Read the chat') }}
                        </label>
                        @if ($replyTo !== [] && $readsChat)
                            <span class="flex items-center gap-1 rounded-full bg-fill px-2 py-0.5 text-chat-text" data-chat-draft-reply-to>
                                <x-icon name="arrow-uturn-left" class="size-3.5" aria-hidden="true" />
                                {{ trans_choice('{1} Answers 1 picked message|[2,*] Answers :count picked messages', count($replyTo), ['count' => count($replyTo)]) }}
                                <button type="button" wire:click="forgetReplyTo" class="text-chat-meta hover:text-chat-text" aria-label="{{ __('Clear') }}">
                                    <x-icon name="x-mark" class="size-3.5" aria-hidden="true" />
                                </button>
                            </span>
                        @endif
                    </div>

                    @if (filled($error))
                        <p role="alert" class="text-xs text-danger" data-chat-draft-error>{{ $error }}</p>
                    @endif

                    <div class="flex items-end gap-2" wire:loading.class="pointer-events-none opacity-60" wire:target="ask">
                        <div class="relative flex min-w-0 flex-1 items-end rounded-[1.5rem] bg-chat-input px-4 py-2 focus-within:ring-2 focus-within:ring-tint/40">
                            <x-whatsapp.mention-list />
                            <div x-ref="field" wire:ignore role="textbox" aria-multiline="true" aria-label="{{ __('What should the message say?') }}" contenteditable="true"
                                 x-bind:data-empty="empty.toString()"
                                 data-placeholder="{{ $messages->isEmpty() ? __('What should the message say?') : __('Ask for a change') }}"
                                 x-on:input="onInput()" x-on:keydown="onKeydown($event)" x-on:paste="onPaste($event)" x-on:keyup="track()" x-on:click="track()"
                                 x-effect="if ($wire.show) $nextTick(() => setTimeout(() => caretToEnd(), 350))"
                                 class="chat-field max-h-40 min-h-6 min-w-0 flex-1 overflow-y-auto text-[0.9375rem] leading-6 break-words whitespace-pre-wrap text-chat-text outline-none"
                                 data-chat-draft-request></div>
                        </div>
                        <x-whatsapp.format-balloon />
                        <button type="button" x-on:click="submit()" x-bind:disabled="empty" aria-label="{{ __('Send') }}" x-tooltip="{{ __('Send') }}"
                                class="grid size-11 shrink-0 place-items-center rounded-full bg-chat-accent text-on-tint hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:opacity-40"
                                data-chat-draft-submit>
                            <x-icon name="paper-airplane" class="size-5" aria-hidden="true" wire:loading.remove wire:target="ask" />
                            <x-icon name="arrow-path" class="size-5 animate-spin" aria-hidden="true" wire:loading wire:target="ask" />
                        </button>
                    </div>
                    <p class="px-1 text-[0.6875rem] text-chat-meta">
                        {{ $this->chat->isGroup() ? __('Type @ to mention someone. Select a text to format it.') : __('Select a text to format it.') }}
                    </p>
                </div>
            @endif
        </div>
    </x-slide>
</div>
