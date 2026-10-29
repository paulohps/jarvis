<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Connect a number" (US-7.1, Workflow 9): creates an Evolution API
 * instance with a unique `instance_name`, stores the user's account as
 * `connecting` under the nickname and returns the QR code to scan. The
 * webhook then brings the new QR codes and the `open` status.
 *
 * Given the phone number, the instance also hands out a pairing code, to
 * link the number by typing the code in WhatsApp instead of scanning.
 *
 * Only for users with the `whatsapp` module; a user may connect as many
 * numbers as they like. The account is only stored once the instance
 * exists, so a refused call leaves nothing behind.
 */
final readonly class ConnectWhatsappAccount
{
    use AuthorizesWhatsappModule;
    use ValidatesPairingNumber;

    public const string INSTANCE_PREFIX = 'jarvis-';

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $user, string $nickname, ?string $phoneNumber = null): WhatsappConnectionAttempt
    {
        $this->authorizeWhatsapp($user);

        /** @var array{nickname: string} $validated */
        $validated = Validator::make(
            ['nickname' => mb_trim($nickname)],
            ['nickname' => ['required', 'string', 'max:100']],
        )->validate();

        $number = $phoneNumber === null ? null : $this->pairingNumber($phoneNumber);

        $instanceName = self::newInstanceName();
        $qrCode = $this->client->createInstance($instanceName, $number);

        $account = $user->whatsappAccounts()->create([
            'nickname' => $validated['nickname'],
            'instance_name' => $instanceName,
            'whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::CONNECTING),
        ]);

        return new WhatsappConnectionAttempt($account, $qrCode);
    }

    /**
     * A fresh instance name, unique among the accounts.
     */
    public static function newInstanceName(): string
    {
        do {
            $name = self::INSTANCE_PREFIX.Str::lower((string) Str::ulid());
        } while (WhatsappAccount::query()->where('instance_name', $name)->exists());

        return $name;
    }
}
