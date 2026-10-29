{{--
    The "New group" dialog: subject, description and members (NewGroup).
--}}
<div data-chat-new-group="{{ $accountId }}">
    <x-modal wire="show" :title="__('New group')">
        <form wire:submit="create" class="flex flex-col gap-4" novalidate data-chat-new-group-form>
            <x-input :label="__('Subject')" wire:model="subject" maxlength="{{ \App\Actions\Whatsapp\CreateWhatsappGroup::MAX_SUBJECT }}" data-chat-new-group-subject />
            <x-textarea :label="__('Description (optional)')" wire:model="description" rows="2" maxlength="2048" />

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-sm font-medium text-label">{{ __('Members') }}</legend>
                <x-ui.search-field name="new-group-search" :placeholder="__('Search contacts')" wire:model.live.debounce.300ms="search" />
                <ul role="list" class="flex max-h-60 flex-col overflow-y-auto">
                    @forelse ($show ? $this->contacts : [] as $contact)
                        <li wire:key="new-group-member-{{ $contact->friendly_id }}">
                            <label class="flex cursor-pointer items-center gap-3 rounded-control px-2 py-1.5 hover:bg-fill">
                                <input type="checkbox" value="{{ $contact->friendly_id }}" wire:model.live="members" class="size-4 rounded border-separator text-tint focus:ring-tint" data-chat-new-group-pick="{{ $contact->friendly_id }}">
                                <span class="min-w-0 flex-1 truncate text-sm text-label">{{ $contact->displayName() }}</span>
                                <span class="shrink-0 text-xs text-secondary-label">{{ $contact->phoneNumber() }}</span>
                            </label>
                        </li>
                    @empty
                        <li class="p-3 text-center text-sm text-secondary-label">{{ __('No contacts found.') }}</li>
                    @endforelse
                </ul>
                @error('members')
                    <p role="alert" class="text-xs text-danger">{{ $message }}</p>
                @enderror
                <p class="text-xs text-secondary-label">{{ trans_choice('{0} Pick at least one member.|{1} 1 member picked.|[2,*] :count members picked.', count($members), ['count' => count($members)]) }}</p>
            </fieldset>

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Create')" icon="user-group" loading="create" data-chat-new-group-create />
            </div>
        </form>
    </x-modal>
</div>
