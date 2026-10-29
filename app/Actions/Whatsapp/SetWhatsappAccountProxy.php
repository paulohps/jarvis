<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The proxy a number's WhatsApp connection goes out through, e.g. a SOCKS5
 * at home reached over a VPN, so the number keeps a residential IP.
 *
 * The input: `enabled` (false goes back to a direct connection), and when
 * enabled `protocol` (one of WhatsappAccount::PROXY_PROTOCOLS), `host`,
 * `port`, `username` and `password` (both optional; a blank password keeps
 * the one saved for the same host and user).
 *
 * The Evolution API is told first (EvolutionApiClient::setProxy()), and it
 * tests the proxy: one that does not answer or does not change the IP is
 * refused under `host`, and nothing is saved. Once saved, a connected
 * number's connection is restarted so it goes out through it.
 *
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class SetWhatsappAccountProxy
{
    use AuthorizesWhatsappModule;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @param  array{enabled?: bool, protocol?: string|null, host?: string|null, port?: int|string|null, username?: string|null, password?: string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException when the Evolution API cannot be reached
     */
    public function __invoke(User $user, WhatsappAccount $account, array $input): WhatsappAccount
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        $proxy = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $this->validated($account, $input) : null;

        try {
            $this->client->setProxy($account->instance_name, $proxy);
        } catch (EvolutionApiException $exception) {
            if ($proxy === null || $exception->status !== 400) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'host' => __('The proxy did not answer, or the connection still goes out with the server IP. Check the address, the port and that the VPN is up.'),
            ]);
        }

        $account->update([
            'proxy_protocol' => $proxy['protocol'] ?? null,
            'proxy_host' => $proxy['host'] ?? null,
            'proxy_port' => $proxy['port'] ?? null,
            'proxy_username' => $proxy['username'] ?? null,
            'proxy_password' => $proxy['password'] ?? null,
        ]);

        if ($account->isOpen()) {
            $this->client->restart($account->instance_name);
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{protocol: string, host: string, port: int, username: string|null, password: string|null}
     *
     * @throws ValidationException
     */
    private function validated(WhatsappAccount $account, array $input): array
    {
        $trimmed = fn (string $key): ?string => filled($input[$key] ?? null) ? mb_trim((string) $input[$key]) : null;

        /** @var array{protocol: string, host: string, port: int|string, username: string|null, password: string|null} $validated */
        $validated = Validator::make([
            'protocol' => $trimmed('protocol'),
            'host' => $trimmed('host'),
            'port' => $trimmed('port'),
            'username' => $trimmed('username'),
            'password' => filled($input['password'] ?? null) ? (string) $input['password'] : null,
        ], [
            'protocol' => ['required', Rule::in(WhatsappAccount::PROXY_PROTOCOLS)],
            'host' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9.\-:\[\]]+\z/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'protocol' => __('protocol'),
            'host' => __('address'),
            'port' => __('port'),
            'username' => __('user'),
            'password' => __('password'),
        ])->validate();

        $keepsPassword = $validated['password'] === null
            && $validated['host'] === $account->proxy_host
            && $validated['username'] === $account->proxy_username;

        return [
            'protocol' => $validated['protocol'],
            'host' => $validated['host'],
            'port' => (int) $validated['port'],
            'username' => $validated['username'],
            'password' => $keepsPassword ? $account->proxy_password : $validated['password'],
        ];
    }
}
