<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\DeleteMailService;
use App\Actions\Mail\SaveMailService;
use App\Actions\Mail\TestMailService;
use App\Http\Controllers\Mail\GmailConnectCallbackController;
use App\Models\MailService;
use App\Models\MailServiceType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The services e-mail goes out through: the form that adds an SMTP server
 * or Resend, or connects a Google account, and the services the user may
 * use — their own, then the global ones. Each can be tested; the ones the
 * user manages can be edited, reconnected (Google) and removed.
 *
 * The password or key is write-only: the field starts empty (empty keeps
 * the stored one) and the list shows only its last four characters. Only
 * the super admin marks a service global.
 *
 * @property-read Collection<int, MailService> $services
 * @property-read Collection<int, MailServiceType> $types
 */
#[Layout('layouts::app-shell')]
class MailServiceList extends Component
{
    public ?string $editingId = null;

    public string $type = MailServiceType::SMTP;

    public string $name = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public string $host = '';

    public string $port = '587';

    public string $encryption = MailService::ENCRYPTION_TLS;

    public string $username = '';

    public string $secret = '';

    public bool $isGlobal = false;

    public ?string $status = null;

    public ?string $error = null;

    /**
     * @var array<string, array{successful: bool, message: string}> service friendly id => outcome of the last test
     */
    public array $testResults = [];

    public function mount(): void
    {
        $status = session(GmailConnectCallbackController::STATUS_KEY);
        $error = session(GmailConnectCallbackController::ERROR_KEY);

        $this->status = is_string($status) ? $status : null;
        $this->error = is_string($error) ? $error : null;
    }

    /**
     * The user's own services, then the global ones.
     *
     * @return Collection<int, MailService>
     */
    #[Computed]
    public function services(): Collection
    {
        $actor = $this->actor();

        return MailService::query()
            ->usableBy($actor)
            ->with(['type', 'user'])
            ->get()
            ->sortBy([
                fn (MailService $first, MailService $second): int => ($first->user_id !== $actor->id) <=> ($second->user_id !== $actor->id),
                fn (MailService $first, MailService $second): int => strnatcasecmp($first->name, $second->name),
            ])
            ->values();
    }

    /**
     * @return Collection<int, MailServiceType>
     */
    #[Computed]
    public function types(): Collection
    {
        return MailServiceType::query()->active()->orderBy('id')->get();
    }

    public function isEditingGmail(): bool
    {
        return $this->type === MailServiceType::GMAIL;
    }

    public function canManage(MailService $service): bool
    {
        return Gate::forUser($this->actor())->allows('update', $service);
    }

    public function edit(string $serviceId): void
    {
        $service = $this->find($serviceId);
        Gate::forUser($this->actor())->authorize('update', $service);

        $this->resetForm();
        $this->editingId = $service->friendly_id;
        $this->type = $service->type->slug;
        $this->name = $service->name;
        $this->fromAddress = $service->from_address;
        $this->fromName = (string) $service->from_name;
        $this->host = (string) $service->host;
        $this->port = (string) ($service->port ?? '');
        $this->encryption = (string) $service->encryption;
        $this->username = (string) $service->username;
        $this->isGlobal = $service->is_global;
    }

    public function cancelEditing(): void
    {
        $this->resetForm();
    }

    public function save(SaveMailService $saveService): void
    {
        $this->clearMessages();

        if ($this->editingId === null && $this->isEditingGmail()) {
            $this->connectGoogle();

            return;
        }

        try {
            $service = $saveService($this->actor(), [
                'type' => $this->type,
                'name' => $this->name,
                'from_address' => $this->fromAddress,
                'from_name' => $this->fromName,
                'host' => $this->host,
                'port' => $this->port,
                'encryption' => $this->encryption === '' ? null : $this->encryption,
                'username' => $this->username,
                'secret' => $this->secret,
                'is_global' => $this->isGlobal,
            ], $this->editingId === null ? null : $this->find($this->editingId));
        } catch (ValidationException $exception) {
            // The action names the fields as the table does.
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [Str::camel($field) => $messages])
                ->all());
        }

        $this->resetForm();
        $this->status = __('Mail service :name saved.', ['name' => $service->name]);
        unset($this->testResults[$service->friendly_id], $this->services);
    }

    /**
     * Off to Google to allow sending; the callback stores the account.
     */
    public function connectGoogle(?string $serviceId = null): void
    {
        $this->clearMessages();

        if ($serviceId !== null) {
            Gate::forUser($this->actor())->authorize('update', $this->find($serviceId));

            $this->redirectRoute('mail-services.google.redirect', ['service' => $serviceId]);

            return;
        }

        $this->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'fromName' => ['nullable', 'string', 'max:255'],
        ]);

        $this->redirectRoute('mail-services.google.redirect', array_filter([
            'name' => trim($this->name),
            'from_name' => trim($this->fromName),
            'is_global' => $this->isGlobal && $this->actor()->isSuperAdmin() ? '1' : '',
        ]));
    }

    public function test(string $serviceId, TestMailService $testService): void
    {
        $this->testResults[$serviceId] = $testService($this->actor(), $this->find($serviceId));
    }

    public function remove(string $serviceId, DeleteMailService $deleteService): void
    {
        $this->clearMessages();

        $service = $this->find($serviceId);
        $deleteService($this->actor(), $service);

        if ($this->editingId === $serviceId) {
            $this->resetForm();
        }

        $this->status = __('Mail service :name removed.', ['name' => $service->name]);
        unset($this->testResults[$serviceId], $this->services);
    }

    public function render(): View
    {
        return view('livewire.mail.mail-service-list')->title(__('Mail services'));
    }

    private function find(string $serviceId): MailService
    {
        return MailService::query()->usableBy($this->actor())->with('type')->where('friendly_id', $serviceId)->firstOrFail();
    }

    private function clearMessages(): void
    {
        $this->resetErrorBag();
        $this->status = null;
        $this->error = null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'type', 'name', 'fromAddress', 'fromName', 'host', 'port', 'encryption', 'username', 'secret', 'isGlobal');
        $this->resetValidation();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
