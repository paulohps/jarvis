{{--
    The user's profile in tabs (Profile, Preferences, Notifications, Devices,
    Security), each an iOS inset grouped list. The tab switches through
    Livewire and is kept in the URL; successes come as toasts.
--}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <x-ui.segmented-control name="profile-tab"
                            :options="$this->tabs()"
                            :value="$tab"
                            :label="__('Profile sections')"
                            wire:model.live="tab"
                            data-profile-tabs />

    <div wire:loading.class="opacity-60" wire:target="tab" class="flex flex-col gap-6 transition-opacity" data-profile-tab="{{ $tab }}">
        @switch($tab)
            @case(\App\Livewire\Profile\ProfilePage::TAB_PROFILE)
                @php
                    $user = auth()->user();
                @endphp

                <form wire:submit="saveProfile" class="flex flex-col gap-6" novalidate data-profile-form>
                    <x-ui.inset-grouped-list :header="__('Photo')" :footer="__('JPG, PNG or WebP, up to 2MB.')">
                        <x-ui.list-row>
                            <x-slot:leading>
                                @if ($avatar !== null && $avatar->isPreviewable())
                                    <img src="{{ $avatar->temporaryUrl() }}" alt="" class="size-14 rounded-full object-cover">
                                @elseif ($user->avatar_url !== null)
                                    <img src="{{ $user->avatar_url }}" alt="" class="size-14 rounded-full object-cover" data-profile-avatar>
                                @else
                                    <span aria-hidden="true" class="grid size-14 place-items-center rounded-full bg-tint-fill text-lg font-semibold text-on-tint">
                                        {{ $user->initials() }}
                                    </span>
                                @endif
                            </x-slot:leading>

                            <label class="flex flex-col gap-1 text-[0.9375rem]">
                                <span class="sr-only">{{ __('Choose a photo') }}</span>
                                <input type="file"
                                       wire:model="avatar"
                                       accept="image/jpeg,image/png,image/webp"
                                       class="text-[0.8125rem] text-secondary-label file:mr-3 file:rounded-control file:border-0 file:bg-fill file:px-3 file:py-1.5 file:text-[0.8125rem] file:font-medium file:text-tint">
                            </label>

                            <div wire:loading wire:target="avatar" class="text-[0.8125rem] text-secondary-label">{{ __('Uploading…') }}</div>

                            @error('avatar')
                                <p class="text-[0.8125rem] text-danger" data-error="avatar">{{ $message }}</p>
                            @enderror
                        </x-ui.list-row>
                    </x-ui.inset-grouped-list>

                    <x-ui.inset-grouped-list :header="__('Account')">
                        <x-ui.list-row>
                            <x-input :label="__('Name')" wire:model="name" autocomplete="name" required />
                        </x-ui.list-row>

                        <x-ui.list-row :title="__('E-mail')" :subtitle="$user->email" />
                    </x-ui.inset-grouped-list>

                    <div class="flex justify-end">
                        <x-button type="submit" :text="__('Save')" icon="check" loading="saveProfile" />
                    </div>
                </form>

                <x-ui.inset-grouped-list :header="__('Workspaces')"
                                         :footer="__('You see the data of all your workspaces together, each in its color. What you create goes to your default workspace unless you pick another.')"
                                         data-profile-workspaces>
                    @forelse ($this->memberships as $membership)
                        <x-ui.list-row wire:key="profile-workspace-{{ $membership->id }}" data-profile-workspace="{{ $membership->workspace->friendly_id }}">
                            <div class="flex flex-col gap-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span aria-hidden="true" class="size-3 shrink-0 rounded-full" style="background-color: {{ \App\Models\Workspace::hexOf($membership->effectiveColor()) }}"></span>
                                        <p class="truncate text-[0.9375rem] font-medium">{{ $membership->workspace->name }}</p>

                                        @if ($membership->is_default)
                                            <x-ui.status-badge status="default" :label="__('Default')" tone="neutral" data-profile-workspace-default />
                                        @endif
                                    </div>

                                    @unless ($membership->is_default)
                                        <x-button :text="__('Make default')" icon="star" sm flat color="secondary"
                                                  wire:click="makeDefaultWorkspace({{ $membership->id }})"
                                                  loading="makeDefaultWorkspace({{ $membership->id }})" />
                                    @endunless
                                </div>

                                <fieldset class="flex flex-wrap items-center gap-2" data-profile-workspace-colors>
                                    <legend class="sr-only">{{ __('Color you see :workspace in', ['workspace' => $membership->workspace->name]) }}</legend>

                                    <label class="cursor-pointer" title="{{ __('Workspace color') }}">
                                        <input type="radio" name="workspace-color-{{ $membership->id }}" value="" wire:model.live="workspaceColors.{{ $membership->id }}" class="peer sr-only">
                                        <span class="grid size-7 place-items-center rounded-full ring-1 ring-separator ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint">
                                            <span class="size-3.5 rounded-full" style="background-color: {{ \App\Models\Workspace::hexOf($membership->workspace->color) }}"></span>
                                        </span>
                                        <span class="sr-only">{{ __('Workspace color') }}</span>
                                    </label>

                                    @foreach (\App\Models\Workspace::COLORS as $colorName => $hex)
                                        <label class="cursor-pointer" title="{{ __(ucfirst($colorName)) }}" wire:key="workspace-color-{{ $membership->id }}-{{ $colorName }}">
                                            <input type="radio" name="workspace-color-{{ $membership->id }}" value="{{ $colorName }}" wire:model.live="workspaceColors.{{ $membership->id }}" class="peer sr-only">
                                            <span class="block size-7 rounded-full ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint"
                                                  style="background-color: {{ $hex }}"></span>
                                            <span class="sr-only">{{ __(ucfirst($colorName)) }}</span>
                                        </label>
                                    @endforeach
                                </fieldset>

                                @error('color')
                                    <p class="text-[0.8125rem] text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                        </x-ui.list-row>
                    @empty
                        <x-ui.list-row icon="user-group" :title="__('No workspace yet')" :subtitle="__('Ask the admin to add you to one.')" />
                    @endforelse
                </x-ui.inset-grouped-list>
                @break

            @case(\App\Livewire\Profile\ProfilePage::TAB_PREFERENCES)
                <form wire:submit="savePreferences" class="flex flex-col gap-6" novalidate data-preferences-form>
                    <x-ui.inset-grouped-list :header="__('Language and region')"
                                             :footer="__('Dates are shown in your timezone, and notifications are written in your language.')">
                        <x-ui.list-row>
                            <x-select.native :label="__('Language')" wire:model="locale" data-locale-select>
                                @foreach ($this->locales() as $slug => $localeName)
                                    <option value="{{ $slug }}" wire:key="locale-{{ $slug }}">{{ $localeName }}</option>
                                @endforeach
                            </x-select.native>
                        </x-ui.list-row>

                        <x-ui.list-row>
                            <x-select.native :label="__('Timezone')" wire:model="timezone" data-timezone-select>
                                @foreach ($this->timezones() as $identifier)
                                    <option value="{{ $identifier }}">{{ str_replace('_', ' ', $identifier) }}</option>
                                @endforeach
                            </x-select.native>
                        </x-ui.list-row>
                    </x-ui.inset-grouped-list>

                    <div class="flex justify-end">
                        <x-button type="submit" :text="__('Save')" icon="check" loading="savePreferences" />
                    </div>
                </form>

                @if ($this->usesWhatsapp())
                    <form wire:submit="savePriorityColors" id="whatsapp-priority-colors" class="flex flex-col gap-6" novalidate data-profile-priority-colors>
                        <x-ui.inset-grouped-list :header="__('WhatsApp priority colors')"
                                                 :footer="__('Every chat with a priority shows its color, on every number. 1 is the highest.')">
                            @foreach (range(\App\Models\WhatsappTarget::HIGHEST_PRIORITY, \App\Models\WhatsappTarget::LOWEST_PRIORITY) as $level)
                                <x-ui.list-row wire:key="priority-colors-{{ $level }}">
                                    <fieldset class="flex flex-wrap items-center gap-2" data-priority-level="{{ $level }}">
                                        <legend class="sr-only">{{ __('Priority :level', ['level' => $level]) }}</legend>
                                        <span class="w-24 shrink-0 text-[0.9375rem]" aria-hidden="true">{{ __('Priority :level', ['level' => $level]) }}</span>
                                        @foreach (\App\Models\WhatsappTarget::PRIORITY_COLORS as $colorName => $hex)
                                            <label class="cursor-pointer" title="{{ __(ucfirst($colorName)) }}" wire:key="priority-color-{{ $level }}-{{ $colorName }}">
                                                <input type="radio" name="priority-color-{{ $level }}" value="{{ $colorName }}" wire:model="priorityColors.{{ $level }}" class="peer sr-only">
                                                <span class="block size-7 rounded-full ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint"
                                                      style="background-color: {{ $hex }}"></span>
                                                <span class="sr-only">{{ __(ucfirst($colorName)) }}</span>
                                            </label>
                                        @endforeach
                                    </fieldset>

                                    @error((string) $level)
                                        <p class="mt-2 text-[0.8125rem] text-danger">{{ $message }}</p>
                                    @enderror
                                </x-ui.list-row>
                            @endforeach
                        </x-ui.inset-grouped-list>

                        <div class="flex justify-end">
                            <x-button type="submit" :text="__('Save colors')" icon="check" loading="savePriorityColors" data-profile-priority-colors-save />
                        </div>
                    </form>
                @endif

                <x-ui.inset-grouped-list :header="__('E-mail')" :footer="__('Send invoices from your own Gmail, SMTP server or Resend account.')">
                    <x-ui.list-row :href="route('mail-services.index')"
                                   :title="__('Mail services')"
                                   :subtitle="__('SMTP, Google account or Resend')"
                                   icon="envelope"
                                   chevron
                                   data-profile-mail-services
                                   wire:navigate />
                </x-ui.inset-grouped-list>
                @break

            @case(\App\Livewire\Profile\ProfilePage::TAB_NOTIFICATIONS)
                @include('livewire.profile.partials.notifications')
                @break

            @case(\App\Livewire\Profile\ProfilePage::TAB_DEVICES)
                @include('livewire.profile.partials.devices')
                @break

            @case(\App\Livewire\Profile\ProfilePage::TAB_SECURITY)
                <form wire:submit="savePassword" class="flex flex-col gap-6" novalidate data-password-form>
                    <x-ui.inset-grouped-list :header="__('Password')"
                                             :footer="$this->hasPassword() ? null : __('You sign in with Google only. Set a password to also sign in with your e-mail.')">
                        @if ($this->hasPassword())
                            <x-ui.list-row>
                                <x-password :label="__('Current password')" wire:model="current_password" autocomplete="current-password" required />
                            </x-ui.list-row>
                        @endif

                        <x-ui.list-row>
                            <x-password :label="__('New password')" wire:model="password" autocomplete="new-password" required />
                        </x-ui.list-row>

                        <x-ui.list-row>
                            <x-password :label="__('Confirm the password')" wire:model="password_confirmation" autocomplete="new-password" required />
                        </x-ui.list-row>
                    </x-ui.inset-grouped-list>

                    <div class="flex justify-end">
                        <x-button type="submit"
                                  :text="$this->hasPassword() ? __('Change the password') : __('Set a password')"
                                  icon="key"
                                  loading="savePassword" />
                    </div>
                </form>

                <x-ui.inset-grouped-list :header="__('Connected accounts')"
                                         :footer="$this->hasPassword() ? null : __('Set a password before disconnecting an account, so you can still sign in.')"
                                         data-connected-accounts>
                    @forelse ($this->socialAccounts as $account)
                        <x-ui.list-row wire:key="social-account-{{ $account->id }}"
                                       icon="link"
                                       :title="__($account->provider->name)"
                                       :subtitle="$account->email"
                                       data-social-account="{{ $account->provider->slug }}">
                            <x-slot:trailing>
                                @if ($this->hasPassword())
                                    <x-ui.confirm-dialog :title="__('Disconnect :provider?', ['provider' => __($account->provider->name)])"
                                                         :description="__('You will sign in with your e-mail and password.')"
                                                         :confirm-label="__('Disconnect')"
                                                         danger
                                                         wire:click="disconnectSocialAccount({{ $account->id }})">
                                        <x-slot:trigger>
                                            <x-button :text="__('Disconnect')" sm flat color="red" />
                                        </x-slot:trigger>
                                    </x-ui.confirm-dialog>
                                @else
                                    <span class="text-[0.8125rem]">{{ __('Connected') }}</span>
                                @endif
                            </x-slot:trailing>
                        </x-ui.list-row>
                    @empty
                        <x-ui.list-row icon="link" :title="__('No connected accounts')" :subtitle="__('Sign in with Google once to connect it.')" />
                    @endforelse
                </x-ui.inset-grouped-list>

                @error('social_account')
                    <p role="alert" class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger" data-error="social_account">{{ $message }}</p>
                @enderror
                @break
        @endswitch
    </div>
</div>
