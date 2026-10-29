{{--
    The dialogs of what the composer sends besides a text or a file: a
    poll, a location, contact cards or a sticker (ChatContent).
--}}
<div data-chat-content="{{ $chatId }}">
    <x-modal wire="show" :title="match ($kind) {
        \App\Livewire\Whatsapp\ChatContent::KIND_POLL => __('Poll'),
        \App\Livewire\Whatsapp\ChatContent::KIND_LOCATION => __('Location'),
        \App\Livewire\Whatsapp\ChatContent::KIND_CONTACTS => __('Contacts'),
        \App\Livewire\Whatsapp\ChatContent::KIND_STICKER => __('Sticker'),
        default => '',
    }">
        <form wire:submit="send" class="flex flex-col gap-5" novalidate data-chat-content-form="{{ $kind }}">
            @if ($replyToId !== null)
                <p class="flex items-center gap-1.5 text-xs text-secondary-label"><x-icon name="arrow-uturn-left" class="size-4" aria-hidden="true" />{{ __('It answers the message picked.') }}</p>
            @endif

            @if ($kind === \App\Livewire\Whatsapp\ChatContent::KIND_POLL)
                <x-input :label="__('Question')" wire:model="question" maxlength="255" data-chat-poll-question />

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium text-label">{{ __('Options') }}</legend>
                    @foreach ($options as $index => $option)
                        <div class="flex items-center gap-2" wire:key="poll-option-{{ $index }}">
                            <div class="flex-1">
                                <x-input wire:model="options.{{ $index }}" maxlength="100" :placeholder="__('Option :number', ['number' => $index + 1])" :aria-label="__('Option :number', ['number' => $index + 1])" data-chat-poll-input="{{ $index }}" />
                            </div>
                            @if (count($options) > \App\Actions\Whatsapp\SendWhatsappChatContent::MIN_POLL_OPTIONS)
                                <x-whatsapp.chat-action size="sm" icon="x-mark" :title="__('Remove')" wire:click="removeOption({{ $index }})" />
                            @endif
                        </div>
                    @endforeach
                    @error('options')
                        <p role="alert" class="text-xs text-danger">{{ $message }}</p>
                    @enderror
                    @foreach ($errors->get('options.*') as $optionErrors)
                        <p role="alert" class="text-xs text-danger">{{ $optionErrors[0] }}</p>
                    @endforeach
                    @if (count($options) < \App\Actions\Whatsapp\SendWhatsappChatContent::MAX_POLL_OPTIONS)
                        <div>
                            <x-button type="button" :text="__('Add an option')" icon="plus" flat sm color="secondary" wire:click="addOption" data-chat-poll-add />
                        </div>
                    @endif
                </fieldset>

                <x-toggle wire:model="multiple" :label="__('Allow more than one answer')" data-chat-poll-multiple />

                <x-toggle wire:model.live="schedules" :label="__('Schedule instead of sending now')" data-chat-poll-schedules />

                @if ($schedules)
                    @if ($replyToId !== null)
                        <p class="-mt-2 text-xs text-secondary-label">{{ __('A scheduled poll goes without answering the message picked.') }}</p>
                    @endif
                    <x-ui.recurrence-picker :form="$recurrence" />
                @endif

                @error('schedule')
                    <p role="alert" class="text-sm text-danger" data-chat-poll-schedule-error>{{ $message }}</p>
                @enderror
            @elseif ($kind === \App\Livewire\Whatsapp\ChatContent::KIND_LOCATION)
                <div x-data="{ locating: false, failed: false }" class="flex flex-col gap-4">
                    <div>
                        <x-button type="button" :text="__('Use where I am')" icon="map-pin" flat sm color="secondary"
                                  x-on:click="locating = true; failed = false; navigator.geolocation.getCurrentPosition(
                                      (position) => { $wire.set('latitude', position.coords.latitude.toFixed(6)); $wire.set('longitude', position.coords.longitude.toFixed(6)); locating = false },
                                      () => { locating = false; failed = true },
                                      { enableHighAccuracy: true, timeout: 10000 })"
                                  x-bind:disabled="locating" data-chat-location-here />
                        <p x-show="failed" x-cloak role="alert" class="mt-1 text-xs text-danger">{{ __('The browser did not tell where you are. Type the coordinates.') }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-input :label="__('Latitude')" wire:model="latitude" inputmode="decimal" placeholder="-23.5505" data-chat-location-latitude />
                        <x-input :label="__('Longitude')" wire:model="longitude" inputmode="decimal" placeholder="-46.6333" data-chat-location-longitude />
                    </div>
                    <x-input :label="__('Place name (optional)')" wire:model="placeName" maxlength="255" data-chat-location-name />
                    @error('name')
                        <p role="alert" class="-mt-3 text-xs text-danger">{{ $message }}</p>
                    @enderror
                    <x-input :label="__('Address (optional)')" wire:model="address" maxlength="500" data-chat-location-address />
                </div>
            @elseif ($kind === \App\Livewire\Whatsapp\ChatContent::KIND_CONTACTS)
                <x-ui.search-field name="chat-content-contacts" :placeholder="__('Search contacts')" wire:model.live.debounce.300ms="contactSearch" />
                <ul role="list" class="flex max-h-72 flex-col overflow-y-auto" data-chat-contacts-list>
                    @forelse ($show ? $this->contacts : [] as $contact)
                        <li wire:key="share-contact-{{ $contact->friendly_id }}">
                            <label class="flex cursor-pointer items-center gap-3 rounded-control px-2 py-1.5 hover:bg-fill">
                                <input type="checkbox" value="{{ $contact->friendly_id }}" wire:model="pickedContacts" class="size-4 rounded border-separator text-tint focus:ring-tint" data-chat-pick-contact="{{ $contact->friendly_id }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-label">{{ $contact->displayName() }}</span>
                                    <span class="block truncate text-xs text-secondary-label">{{ $contact->phoneNumber() }}</span>
                                </span>
                            </label>
                        </li>
                    @empty
                        <li class="p-4 text-center text-sm text-secondary-label">{{ __('No contacts found.') }}</li>
                    @endforelse
                </ul>
                @error('contacts')
                    <p role="alert" class="text-xs text-danger">{{ $message }}</p>
                @enderror
            @elseif ($kind === \App\Livewire\Whatsapp\ChatContent::KIND_STICKER)
                <label class="flex cursor-pointer flex-col items-center gap-3 rounded-card border-2 border-dashed border-separator p-6 text-center hover:bg-fill" data-chat-sticker-pick>
                    @if ($sticker !== null && str_starts_with((string) $sticker->getMimeType(), 'image/'))
                        <img src="{{ $sticker->temporaryUrl() }}" alt="" class="size-32 object-contain">
                    @else
                        <x-icon name="face-smile" class="size-10 text-secondary-label" aria-hidden="true" />
                    @endif
                    <span class="text-sm text-label">{{ $sticker !== null ? __('Pick another image') : __('Pick an image (WebP, PNG or JPEG, up to 1 MB)') }}</span>
                    <input type="file" wire:model="sticker" accept="image/webp,image/png,image/jpeg" class="sr-only">
                </label>
                <div wire:loading wire:target="sticker" class="text-xs text-secondary-label">{{ __('Uploading…') }}</div>
                <p class="text-xs text-secondary-label">{{ __('A PNG or JPEG becomes a still sticker; a WebP goes as it is.') }}</p>
                @error('sticker')
                    <p role="alert" class="text-xs text-danger">{{ $message }}</p>
                @enderror
            @endif

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                @if ($kind === \App\Livewire\Whatsapp\ChatContent::KIND_POLL && $schedules)
                    <x-button type="submit" :text="__('Schedule poll')" icon="clock" loading="send" data-chat-content-schedule />
                @else
                    <x-button type="submit" :text="__('Send')" icon="paper-airplane" loading="send" data-chat-content-send />
                @endif
            </div>
        </form>
    </x-modal>
</div>
