<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\ConnectWhatsappAccount;
use App\Actions\Whatsapp\RenewWhatsappQrCode;
use App\Actions\Whatsapp\RequestWhatsappPairingCode;
use App\Events\NotificationCreated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Services\Whatsapp\EvolutionQrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Connects a WhatsApp number by QR code (US-7.1, Workflow 9), or reconnects
 * one of the user's accounts (`whatsapp_account_disconnected` links here).
 *
 * New: the user names the number and gets the QR code
 * (ConnectWhatsappAccount). The QR code then swaps without a refresh when
 * the webhook brings a new one (`WhatsappQrCodeUpdated` on the user's
 * private channel), and the screen also renews it itself every
 * `RENEW_SECONDS` while it stays open and the number is not connected
 * (RenewWhatsappQrCode). Once the webhook reports `open`
 * (`WhatsappAccountStatusChanged`), the screen shows the account connected.
 *
 * Instead of the QR code the user may link the number by phone number: they
 * type it and get the pairing code to type in WhatsApp — straight away for
 * a new number (ConnectWhatsappAccount), or by moving the account to an
 * instance started with the number (RequestWhatsappPairingCode). The code
 * then follows the QR codes: it comes with each webhook and each renewal.
 *
 * @property-read WhatsappAccount|null $account
 */
#[Layout('layouts::app-shell')]
class ConnectAccount extends Component
{
    public const int RENEW_SECONDS = 45;

    #[Locked]
    public int $userId = 0;

    /**
     * The friendly id of the account being connected; null before the
     * nickname is sent.
     */
    #[Locked]
    public ?string $accountId = null;

    public string $nickname = '';

    /**
     * The QR code image (`data:image/png;base64,...`) to scan.
     */
    #[Locked]
    public ?string $qrCode = null;

    #[Locked]
    public ?string $status = null;

    /**
     * Whether the user links the number by phone number (pairing code)
     * rather than by scanning the QR code.
     */
    public bool $linkByCode = false;

    public string $phoneNumber = '';

    /**
     * The number the pairing code was requested for (digits only); null
     * while linking by QR code.
     */
    #[Locked]
    public ?string $pairingNumber = null;

    /**
     * The 8-character code to type in WhatsApp.
     */
    #[Locked]
    public ?string $pairingCode = null;

    public ?string $error = null;

    public function mount(?WhatsappAccount $account = null): void
    {
        $this->userId = $this->user()->id;

        if ($account === null) {
            return;
        }

        $this->authorize('use', $account);

        $this->accountId = $account->friendly_id;
        $this->nickname = $account->nickname;
        $this->status = $account->status->slug;

        $this->renew(app(RenewWhatsappQrCode::class));
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = 'echo-private:'.NotificationCreated::channelFor($this->userId);

        return [
            "{$channel},.whatsapp.qr-code.updated" => 'qrCodeUpdated',
            "{$channel},.whatsapp.account.status-changed" => 'statusChanged',
        ];
    }

    #[Computed]
    public function account(): ?WhatsappAccount
    {
        if ($this->accountId === null) {
            return null;
        }

        return $this->user()->whatsappAccounts()->where('friendly_id', $this->accountId)->first();
    }

    public function isOpen(): bool
    {
        return $this->status === WhatsappConnectionStatus::OPEN;
    }

    public function connect(ConnectWhatsappAccount $connectAccount): void
    {
        if ($this->accountId !== null) {
            return;
        }

        $this->error = null;
        $this->resetErrorBag();

        $phoneNumber = $this->linkByCode ? $this->phoneNumber : null;

        try {
            $attempt = $connectAccount($this->user(), $this->nickname, $phoneNumber);
        } catch (ValidationException $exception) {
            throw self::onPhoneNumberField($exception);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached. Try again in a moment.');

            return;
        }

        $this->accountId = $attempt->account->friendly_id;
        $this->nickname = $attempt->account->nickname;
        $this->status = WhatsappConnectionStatus::CONNECTING;
        $this->qrCode = $attempt->qrCode?->base64;
        $this->pairingNumber = $phoneNumber === null ? null : self::digitsOf($phoneNumber);
        $this->pairingCode = $attempt->qrCode?->pairingCode;
        unset($this->account);
    }

    /**
     * Links the account being connected by phone number: "Get code" and
     * "New code".
     */
    public function requestPairingCode(RequestWhatsappPairingCode $requestPairingCode): void
    {
        $account = $this->account;

        if ($account === null || $account->isOpen()) {
            $this->syncStatus();

            return;
        }

        $this->error = null;
        $this->resetErrorBag();

        try {
            $qrCode = $requestPairingCode($this->user(), $account, $this->phoneNumber);
        } catch (ValidationException $exception) {
            throw self::onPhoneNumberField($exception);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached. Try again in a moment.');

            return;
        }

        $this->linkByCode = true;
        $this->pairingNumber = self::digitsOf($this->phoneNumber);
        $this->pairingCode = $qrCode?->pairingCode;
        $this->qrCode = $qrCode->base64 ?? $this->qrCode;
        $this->syncStatus();
    }

    /**
     * Back to the QR code, which the instance keeps handing out alongside
     * the pairing code.
     */
    public function useQrCode(): void
    {
        $this->linkByCode = false;
        $this->resetErrorBag('phoneNumber');
    }

    /**
     * A fresh QR code while the number is not connected: on opening, every
     * `RENEW_SECONDS` and on "New QR code".
     */
    public function renew(RenewWhatsappQrCode $renewQrCode): void
    {
        $account = $this->account;

        if ($account === null || $account->isOpen()) {
            $this->syncStatus();

            return;
        }

        try {
            $this->showQrCode($renewQrCode($this->user(), $account, $this->pairingNumber));
            $this->error = null;
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached. Try again in a moment.');
        }

        $this->syncStatus();
    }

    /**
     * @param  array{account_id?: string, qr_code?: string, pairing_code?: string|null}  $payload
     */
    public function qrCodeUpdated(array $payload): void
    {
        if (($payload['account_id'] ?? null) !== $this->accountId || ! is_string($payload['qr_code'] ?? null) || $this->isOpen()) {
            return;
        }

        $this->showQrCode(new EvolutionQrCode(
            $payload['qr_code'],
            pairingCode: is_string($payload['pairing_code'] ?? null) ? $payload['pairing_code'] : null,
        ));
    }

    /**
     * The pairing code to show, `ABCD-EFGH` as WhatsApp reads it.
     */
    public function formattedPairingCode(): ?string
    {
        if ($this->pairingCode === null) {
            return null;
        }

        return mb_strlen($this->pairingCode) === 8
            ? mb_substr($this->pairingCode, 0, 4).'-'.mb_substr($this->pairingCode, 4)
            : $this->pairingCode;
    }

    /**
     * @param  array{account_id?: string, status?: string}  $payload
     */
    public function statusChanged(array $payload): void
    {
        if (($payload['account_id'] ?? null) !== $this->accountId) {
            return;
        }

        $this->syncStatus();
    }

    public function render(): View
    {
        return view('livewire.whatsapp.connect-account')
            ->title($this->accountId === null ? __('Connect a number') : __('Connect :nickname', ['nickname' => $this->nickname]));
    }

    /**
     * The status as stored: a broadcast only says something changed.
     */
    private function syncStatus(): void
    {
        unset($this->account);

        $this->status = $this->account?->status->slug ?? $this->status;

        if ($this->isOpen()) {
            $this->qrCode = null;
            $this->pairingCode = null;
        }
    }

    /**
     * A fresh QR code (and, when linking by phone number, its pairing code);
     * null keeps what is shown.
     */
    private function showQrCode(?EvolutionQrCode $qrCode): void
    {
        $this->qrCode = $qrCode->base64 ?? $this->qrCode;

        if ($this->pairingNumber !== null) {
            $this->pairingCode = $qrCode->pairingCode ?? $this->pairingCode;
        }
    }

    /**
     * The Actions name the field `phone_number`; on this screen it is
     * `phoneNumber`.
     */
    private static function onPhoneNumberField(ValidationException $exception): ValidationException
    {
        $errors = $exception->errors();

        if (array_key_exists('phone_number', $errors)) {
            $errors['phoneNumber'] = $errors['phone_number'];
            unset($errors['phone_number']);
        }

        return ValidationException::withMessages($errors);
    }

    private static function digitsOf(string $phoneNumber): string
    {
        return (string) preg_replace('/\D/', '', $phoneNumber);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
