<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through a connected Google account with the Gmail API
 * (`users.messages.send`, scope `gmail.send`) instead of SMTP: the refresh
 * token stored with the service buys an access token (kept in the cache
 * until shortly before it expires), and the message goes as raw MIME.
 *
 * Gmail sets the sender to the account itself (or one of its verified
 * aliases). A refresh token Google no longer accepts (`invalid_grant`:
 * revoked, password changed, or older than 7 days while the OAuth app is in
 * "Testing") fails with a message that asks to connect the account again.
 */
final class GmailTransport extends AbstractTransport
{
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public const string SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    public function __construct(
        private readonly string $refreshToken,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly int $timeout = 30,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'gmail+api://default';
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->accessToken())
                ->post(self::SEND_URL, ['raw' => self::base64Url($this->rawMessage($message))]);
        } catch (ConnectionException $exception) {
            throw new TransportException(__('Gmail could not be reached: :message', ['message' => $exception->getMessage()]), 0, $exception);
        }

        if ($response->failed()) {
            throw new TransportException(__('Gmail refused the message: :message', ['message' => self::errorOf($response)]));
        }

        $id = $response->json('id');

        if (is_string($id)) {
            $message->setMessageId($id);
        }
    }

    /**
     * A valid access token, refreshed when the cached one is gone.
     */
    private function accessToken(): string
    {
        $key = 'mail-services:gmail-token:'.hash('sha256', $this->refreshToken);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout($this->timeout)->post(self::TOKEN_URL, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $exception) {
            throw new TransportException(__('Google could not be reached: :message', ['message' => $exception->getMessage()]), 0, $exception);
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new TransportException($response->json('error') === 'invalid_grant'
                ? __('Google no longer accepts this account’s connection (revoked or expired). Connect the Google account again.')
                : __('Google refused the connection: :message', ['message' => self::errorOf($response)]));
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($key, $token, now()->addSeconds(max(60, $expiresIn - 120)));

        return $token;
    }

    /**
     * The MIME of the message, with a Bcc header put back for the
     * recipients only the envelope has (Symfony drops the header; Gmail
     * reads the recipients from the headers and strips Bcc itself).
     */
    private function rawMessage(SentMessage $message): string
    {
        $raw = $message->toString();
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            return $raw;
        }

        $visible = array_map(
            static fn (Address $address): string => strtolower($address->getAddress()),
            [...$original->getTo(), ...$original->getCc()],
        );

        $hidden = array_values(array_filter(
            $message->getEnvelope()->getRecipients(),
            static fn (Address $address): bool => ! in_array(strtolower($address->getAddress()), $visible, true),
        ));

        if ($hidden === []) {
            return $raw;
        }

        $bcc = 'Bcc: '.implode(', ', array_map(static fn (Address $address): string => $address->getAddress(), $hidden));

        return $bcc."\r\n".$raw;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function errorOf(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error');

        return is_string($message) && $message !== '' ? $message : "HTTP {$response->status()}";
    }
}
