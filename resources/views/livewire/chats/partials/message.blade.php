{{--
    One message of the chat screen, whatever its channel: the bubble with
    the sender (in a group or channel), the text as the channel renders it,
    the files, the reactions and the thread count; on hover, what the
    channel allows (react, reply in a thread, edit, delete) and what Jarvis
    adds (pick for the AI or a reminder).

    Expects: $message (ChatMessageData), $capabilities, $timezone,
    $startsRun, $showsSender, $inThread, $selected, $canRemind, $quickReactions.
--}}
@php
    $sentAt = $message->sentAt->setTimezone($timezone);
    $isSelected = in_array($message->id, $selected, true);
    $isSystem = $message->kind === 'system';
@endphp

@if ($isSystem)
    <li wire:key="{{ $inThread ? 'thread' : 'message' }}-{{ $message->id }}" class="my-1 flex justify-center" data-chat-message="{{ $message->id }}" data-from-me="false" data-system>
        <span class="max-w-[85%] rounded-control bg-chat-bubble-in px-3 py-1 text-center text-xs text-chat-meta shadow-soft">{!! $message->html !!}</span>
    </li>
@else
    <li wire:key="{{ $inThread ? 'thread' : 'message' }}-{{ $message->id }}"
        @class(['group flex items-end gap-1', 'flex-row-reverse' => $message->fromMe, 'mb-4' => $message->reactions !== []])
        data-chat-message="{{ $message->id }}" data-from-me="{{ $message->fromMe ? 'true' : 'false' }}">
        @if ($showsSender)
            <div class="w-8 shrink-0 self-start">
                @if ($startsRun)
                    <x-whatsapp.avatar size="sm" :name="$message->senderName ?? '?'" :picture="$message->senderPictureUrl" data-chat-sender-avatar="{{ $message->senderId }}" />
                @endif
            </div>
        @endif

        <div @class([
            'relative max-w-[85%] rounded-[0.5rem] px-2 pt-1.5 pb-1 text-[0.9375rem] leading-snug text-chat-text shadow-soft md:max-w-[65%]',
            'rounded-tr-none' => $message->fromMe && $startsRun,
            'rounded-tl-none' => ! $message->fromMe && $startsRun,
            'bg-chat-bubble-out' => $message->fromMe,
            'bg-chat-bubble-in' => ! $message->fromMe,
            'ring-2 ring-tint ring-offset-2 ring-offset-grouped-background' => $isSelected,
            'opacity-75 outline-2 outline-dashed outline-offset-2 outline-danger/50' => $message->isDeleted(),
        ])>
            @if ($startsRun && $showsSender && $message->senderName !== null)
                <p class="text-[0.8125rem] font-medium text-chat-link" data-chat-sender>{{ $message->senderName }}</p>
            @endif

            @if ($message->isDeleted())
                <p class="flex items-center gap-1 text-sm text-chat-meta italic" data-chat-deleted>
                    <x-icon name="no-symbol" class="size-4" aria-hidden="true" />{{ __('Message deleted') }}
                </p>
            @else
                @if ($message->html !== '')
                    <div class="chat-body break-words whitespace-pre-wrap" data-chat-body>{!! $message->html !!}</div>
                @endif

                @foreach ($message->files as $file)
                    <div wire:key="file-{{ $message->id }}-{{ $file->id }}" class="mt-1" data-chat-file="{{ $file->id }}" data-chat-file-kind="{{ $file->kind }}">
                        @if ($file->kind === 'image' && $file->url !== null)
                            <a href="{{ $file->url }}" target="_blank" rel="noopener" class="block">
                                <img src="{{ $file->url }}" alt="{{ $file->name }}" loading="lazy" class="block max-h-[338px] min-h-16 max-w-full rounded-control bg-fill object-contain" />
                            </a>
                        @elseif ($file->url !== null)
                            <a href="{{ $file->url }}" target="_blank" rel="noopener"
                               class="flex items-center gap-2 rounded-control bg-fill px-2 py-1.5 text-sm text-chat-text hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                                <x-icon :name="match ($file->kind) { 'video' => 'video-camera', 'audio' => 'microphone', default => 'document' }" class="size-5 shrink-0 text-chat-meta" aria-hidden="true" />
                                <span class="min-w-0 flex-1 truncate">{{ $file->name }}</span>
                                @if ($file->size !== null)
                                    <span class="shrink-0 text-xs text-chat-meta">{{ \Illuminate\Support\Number::fileSize($file->size) }}</span>
                                @endif
                            </a>
                        @else
                            <p class="flex items-center gap-2 text-sm text-chat-meta">
                                <x-icon name="document" class="size-5 shrink-0" aria-hidden="true" />{{ $file->name }} · {{ __('Open it on the channel') }}
                            </p>
                        @endif
                    </div>
                @endforeach
            @endif

            <p class="mt-0.5 flex items-center justify-end gap-1 text-[0.6875rem] text-chat-meta">
                @if ($message->editedAt !== null && ! $message->isDeleted())
                    <span data-chat-edited>{{ __('edited') }}</span>
                @endif
                <time datetime="{{ $message->sentAt->toIso8601String() }}" x-tooltip="{{ $sentAt->isoFormat('LLLL') }}">{{ $sentAt->format('H:i') }}</time>
            </p>

            @if ($message->reactions !== [])
                <div @class(['absolute -bottom-4 flex gap-1', 'right-2' => $message->fromMe, 'left-2' => ! $message->fromMe]) data-chat-reactions>
                    @foreach ($message->reactions as $reaction)
                        <button type="button" wire:key="reaction-{{ $message->id }}-{{ md5($reaction->emoji) }}"
                                @if ($capabilities->reactions) wire:click="react('{{ $message->id }}', '{{ $reaction->emoji }}', {{ $reaction->mine ? 'false' : 'true' }})" @else disabled @endif
                                x-tooltip="{{ implode(', ', $reaction->names) }}"
                                @class(['flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-sm shadow-soft ring-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                                        'bg-chat-bubble-out ring-chat-unread' => $reaction->mine, 'bg-chat-bubble-in ring-chat-wallpaper' => ! $reaction->mine])
                                data-chat-reaction="{{ $reaction->emoji }}" data-mine="{{ $reaction->mine ? 1 : 0 }}">
                            <span>{{ $reaction->emoji }}</span>
                            @if ($reaction->count > 1)
                                <span class="text-xs text-chat-meta">{{ $reaction->count }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            @if (! $inThread && $capabilities->threads && $message->replyCount > 0)
                <button type="button" wire:click="openThread('{{ $message->id }}')"
                        class="mt-1 flex items-center gap-1 text-xs font-medium text-chat-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                        data-chat-open-thread="{{ $message->id }}">
                    <x-icon name="chat-bubble-left-ellipsis" class="size-4" aria-hidden="true" />
                    {{ trans_choice('{1} 1 reply|[2,*] :count replies', $message->replyCount, ['count' => $message->replyCount]) }}
                </button>
            @endif
        </div>

        @unless ($message->isDeleted())
            <div class="flex shrink-0 items-center gap-0.5 self-center opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100" data-chat-message-actions>
                @if ($capabilities->reactions)
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                        <x-whatsapp.chat-action icon="face-smile" :title="__('React')" size="sm" x-on:click="open = ! open" aria-haspopup="true" x-bind:aria-expanded="open.toString()" data-chat-react="{{ $message->id }}" />
                        <div x-show="open" x-cloak x-transition.opacity.duration.100ms x-on:click="open = false"
                             @class(['absolute bottom-full z-30 mb-1 flex items-center gap-0.5 rounded-full bg-secondary-grouped-background p-1 shadow-lg ring-1 ring-separator', 'right-0' => $message->fromMe, 'left-0' => ! $message->fromMe])>
                            @foreach ($quickReactions as $emoji)
                                <button type="button" wire:click="react('{{ $message->id }}', '{{ $emoji }}', true)" class="grid size-8 place-items-center rounded-full text-xl hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                        data-chat-quick-reaction="{{ $emoji }}">{{ $emoji }}</button>
                            @endforeach
                            <button type="button" wire:click="pickEmoji('{{ $message->id }}')" class="grid size-8 place-items-center rounded-full text-chat-meta hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                    aria-label="{{ __('More emojis') }}" data-chat-more-emojis>
                                <x-icon name="plus" class="size-5" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                @endif

                @if (! $inThread && $capabilities->threads)
                    <x-whatsapp.chat-action icon="chat-bubble-left-ellipsis" :title="__('Reply in thread')" size="sm" wire:click="openThread('{{ $message->id }}')" data-chat-reply-thread="{{ $message->id }}" />
                @endif

                @if (! $inThread)
                    <x-whatsapp.chat-action :icon="$isSelected ? 'check-circle' : 'sparkles'" :title="$isSelected ? __('Unpick') : __('Pick for the AI or a reminder')" size="sm" :active="$isSelected"
                                            wire:click="toggleSelected('{{ $message->id }}')" data-chat-select="{{ $message->id }}" />
                @endif

                @if ($message->canEdit)
                    <x-whatsapp.chat-action icon="pencil" :title="__('Edit')" size="sm" wire:click="startEdit('{{ $message->id }}')" data-chat-edit="{{ $message->id }}" />
                @endif

                @if ($message->canDelete)
                    <x-whatsapp.chat-action icon="trash" :title="__('Delete')" size="sm" wire:click="deleteMessage('{{ $message->id }}')" wire:confirm="{{ __('Delete this message on the channel?') }}" data-chat-delete="{{ $message->id }}" />
                @endif

                @if ($canRemind && ! $inThread)
                    <x-whatsapp.chat-action icon="bell-alert" :title="__('Remind me about this')" size="sm" wire:click="startReminder('{{ $message->id }}')" data-chat-remind="{{ $message->id }}" />
                @endif

                @if ($message->externalUrl !== null && $capabilities->externalLink)
                    <a href="{{ $message->externalUrl }}" target="_blank" rel="noopener" x-tooltip="{{ __('Open on the channel') }}" aria-label="{{ __('Open on the channel') }}"
                       class="grid size-8 place-items-center rounded-full bg-secondary-grouped-background text-label shadow-soft hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                        <x-icon name="arrow-top-right-on-square" class="size-5" aria-hidden="true" />
                    </a>
                @endif
            </div>
        @endunless
    </li>
@endif
