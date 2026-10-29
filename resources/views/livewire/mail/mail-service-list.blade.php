{{--
    The mail services: the form (an SMTP server or Resend, or a Google
    account connected on Google's own screen; the password or key is
    write-only), then the services the user may use — their own first, then
    the global ones — each with its sender, "test", and, for the ones the
    user manages, edit, reconnect (Google) and remove.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    @if (filled($status))
        <p role="status" data-mail-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if (filled($error))
        <p role="alert" data-mail-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    <x-ui.settings-section :title="$editingId === null ? __('Add a mail service') : __('Edit the mail service')"
                           :description="__('Pick one of these where e-mail is sent (invoices, notifications). Without one, the application’s default mailer sends.')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-mail-form>
            <x-select.native :label="__('Service')" wire:model.live="type" :disabled="$editingId !== null" data-mail-type>
                @foreach ($this->types as $option)
                    <option value="{{ $option->slug }}" wire:key="mail-type-{{ $option->slug }}">{{ $option->name }}</option>
                @endforeach
            </x-select.native>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-input :label="$this->isEditingGmail() && $editingId === null ? __('Name (optional)') : __('Name')"
                         wire:model="name" maxlength="100" autocomplete="off" :placeholder="__('Personal Gmail, Company SMTP…')" />
                <x-input :label="__('Sender name (optional)')" wire:model="fromName" maxlength="255" autocomplete="off" />
            </div>

            @unless ($this->isEditingGmail())
                <x-input type="email" :label="__('Sender address')" wire:model="fromAddress" maxlength="255" autocomplete="off"
                         :hint="$type === \App\Models\MailServiceType::RESEND ? __('Its domain has to be verified in Resend.') : null"
                         required data-mail-from-address />
            @endunless

            @if ($type === \App\Models\MailServiceType::SMTP)
                <div class="grid gap-5 sm:grid-cols-[1fr_8rem_10rem]">
                    <x-input :label="__('Server')" wire:model="host" maxlength="255" autocomplete="off" placeholder="smtp.example.com" required data-mail-host />
                    <x-input type="number" :label="__('Port')" wire:model="port" min="1" max="65535" required />
                    <x-select.native :label="__('Encryption')" wire:model="encryption">
                        <option value="{{ \App\Models\MailService::ENCRYPTION_TLS }}">STARTTLS</option>
                        <option value="{{ \App\Models\MailService::ENCRYPTION_SSL }}">SSL/TLS</option>
                    </x-select.native>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-input :label="__('User (optional)')" wire:model="username" maxlength="255" autocomplete="off" />
                    <x-password :label="__('Password')" wire:model="secret" autocomplete="new-password"
                                :hint="$editingId === null ? null : __('Leave empty to keep the stored password.')" />
                </div>
            @elseif ($type === \App\Models\MailServiceType::RESEND)
                <x-password :label="__('API key')" wire:model="secret" autocomplete="new-password"
                            :hint="$editingId === null ? null : __('Leave empty to keep the stored key.')" data-mail-secret />
            @elseif ($editingId === null)
                <p class="text-[0.875rem] text-pretty text-secondary-label" data-mail-gmail-hint>
                    {{ __('You will go to Google to choose the account and allow Jarvis to send e-mail from it. E-mail goes out from that address.') }}
                </p>
            @endif

            @if (auth()->user()->isSuperAdmin())
                <x-toggle wire:model="isGlobal" :label="__('Offer it to every user')" data-mail-global />
            @endif

            <div class="flex justify-end gap-2">
                @if ($editingId !== null)
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" wire:click="cancelEditing" />
                @endif

                @if ($this->isEditingGmail() && $editingId === null)
                    <x-button type="submit" :text="__('Connect Google account')" icon="arrow-top-right-on-square" loading="save" data-mail-connect-google />
                @else
                    <x-button type="submit" :text="__('Save the mail service')" icon="check" loading="save" />
                @endif
            </div>
        </form>
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('Mail services')">
        @if ($this->services->isEmpty())
            <x-ui.empty-state icon="envelope"
                              :title="__('No mail service yet')"
                              :description="__('E-mail goes out through the application’s default mailer.')" />
        @else
            <ul role="list" class="divide-y divide-separator">
                @foreach ($this->services as $service)
                    @php
                        $canManage = $this->canManage($service);
                    @endphp

                    <li wire:key="mail-service-{{ $service->friendly_id }}"
                        data-mail-service="{{ $service->friendly_id }}"
                        class="flex flex-col gap-3 py-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-[0.9375rem] font-medium text-label">{{ $service->name }}</p>

                                    @if ($service->is_global)
                                        <x-ui.status-badge status="global" tone="info" :label="__('Global')" data-mail-global-badge />
                                    @endif
                                </div>

                                <p class="text-[0.8125rem] break-words text-secondary-label">
                                    {{ $service->type->name }} · {{ $service->sender() }}
                                    @if ($canManage && $service->maskedSecret() !== null)
                                        · <span data-mail-secret-mask>{{ $service->maskedSecret() }}</span>
                                    @endif
                                    @if ($service->user_id !== auth()->id())
                                        · {{ __('Shared by :name', ['name' => $service->user->name]) }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2" data-mail-service-actions>
                                <x-button :text="__('Test')" icon="bolt" sm flat color="secondary"
                                          wire:click="test('{{ $service->friendly_id }}')" loading="test('{{ $service->friendly_id }}')"
                                          data-mail-test="{{ $service->friendly_id }}" />

                                @if ($canManage)
                                    @if ($service->type->slug === \App\Models\MailServiceType::GMAIL)
                                        <x-button :text="__('Reconnect')" icon="arrow-path" sm flat color="secondary"
                                                  wire:click="connectGoogle('{{ $service->friendly_id }}')"
                                                  data-mail-reconnect="{{ $service->friendly_id }}" />
                                    @endif

                                    <x-button :text="__('Edit')" icon="pencil-square" sm flat color="secondary"
                                              wire:click="edit('{{ $service->friendly_id }}')"
                                              data-mail-edit="{{ $service->friendly_id }}" />

                                    <x-ui.confirm-dialog :title="__('Remove :name?', ['name' => $service->name])"
                                                         :description="__('Whatever sends through it goes back to the default mailer.')"
                                                         :confirm-label="__('Remove')"
                                                         danger
                                                         wire:click="remove('{{ $service->friendly_id }}')">
                                        <x-slot:trigger>
                                            <x-button :text="__('Remove')" icon="trash" sm flat color="red"
                                                      data-mail-remove="{{ $service->friendly_id }}" />
                                        </x-slot:trigger>
                                    </x-ui.confirm-dialog>
                                @endif
                            </div>
                        </div>

                        @isset($testResults[$service->friendly_id])
                            @php
                                $result = $testResults[$service->friendly_id];
                            @endphp

                            <p role="status"
                               data-mail-test-result="{{ $result['successful'] ? 'success' : 'failure' }}"
                                @class([
                                    'rounded-control px-4 py-2 text-sm break-words',
                                    'bg-success/10 text-success' => $result['successful'],
                                    'bg-danger/10 text-danger' => ! $result['successful'],
                                ])>
                                {{ $result['successful'] ? $result['message'] : __('The e-mail could not be sent: :message', ['message' => $result['message']]) }}
                            </p>
                        @endisset
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.settings-section>
</div>
