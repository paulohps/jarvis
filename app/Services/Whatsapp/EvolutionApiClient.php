<?php

namespace App\Services\Whatsapp;

use App\Exceptions\EvolutionApiException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The self-hosted Evolution API v2 (Key Concept "WhatsApp account"): one
 * instance per connected number, authenticated by the global `apikey`
 * header. Endpoints checked against the v2.3 sources:
 *
 * - `POST /instance/create`, `GET /instance/connect/{instance}` (both take
 *   the `number` that turns on the pairing code);
 * - `GET /instance/connectionState/{instance}`, `POST /instance/restart/{instance}`;
 * - `DELETE /instance/logout/{instance}`, `DELETE /instance/delete/{instance}`;
 * - `POST /chat/findContacts/{instance}`, `GET /group/fetchAllGroups/{instance}`;
 * - `GET /group/inviteInfo/{instance}`, `GET /group/acceptInviteCode/{instance}`
 *   (`?inviteCode=` from a `chat.whatsapp.com/…` link);
 * - `POST /message/sendText/{instance}`;
 * - `POST /webhook/set/{instance}`;
 * - `GET /settings/find/{instance}`, `POST /settings/set/{instance}`;
 * - `POST /chat/findMessages/{instance}` (the messages the Evolution API
 *   keeps when `DATABASE_SAVE_DATA_NEW_MESSAGE` / `_HISTORIC` are on).
 *
 * Every call times out after `services.evolution.timeout` seconds (10) —
 * listing contacts and groups after `services.evolution.sync_timeout` (50),
 * since WhatsApp answers those slowly — and a status of 400 or above, or no
 * answer at all, throws `EvolutionApiException`. Tests fake it with `FakeEvolutionApi`.
 */
final readonly class EvolutionApiClient
{
    /**
     * The webhook events an instance reports to us: the QR codes and
     * connection changes, the messages as they come and go
     * (`MESSAGES_UPSERT`, with the poll votes and pins), the history handed
     * over on linking (`MESSAGES_SET`), the receipts of what the number sent
     * (`MESSAGES_UPDATE`), the messages deleted for everyone
     * (`MESSAGES_DELETE`) and edited (`MESSAGES_EDITED`), what it sent
     * through the API (`SEND_MESSAGE`, `SEND_MESSAGE_UPDATE`: they never come
     * as `MESSAGES_UPSERT`), the presences, and the groups it joins or whose
     * members change (`GROUPS_UPSERT`, `GROUP_UPDATE`,
     * `GROUP_PARTICIPANTS_UPDATE`).
     */
    public const array WEBHOOK_EVENTS = [
        'QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT', 'MESSAGES_SET', 'MESSAGES_UPDATE', 'MESSAGES_DELETE', 'MESSAGES_EDITED',
        'SEND_MESSAGE', 'SEND_MESSAGE_UPDATE', 'PRESENCE_UPDATE', 'GROUPS_UPSERT', 'GROUP_UPDATE', 'GROUP_PARTICIPANTS_UPDATE',
    ];

    /**
     * The header that carries the shared webhook secret.
     */
    public const string WEBHOOK_SECRET_HEADER = 'X-Evolution-Secret';

    public function __construct(
        #[Config('services.evolution.url')] private string $baseUrl,
        #[Config('services.evolution.key')] private ?string $apiKey,
        #[Config('services.evolution.webhook_url')] private ?string $webhookUrl = null,
        #[Config('services.evolution.webhook_secret')] private ?string $webhookSecret = null,
        #[Config('services.evolution.timeout')] private int $timeout = 10,
        #[Config('services.evolution.sync_timeout')] private int $syncTimeout = 50,
    ) {}

    /**
     * Creates a Baileys instance that reports its QR codes, connection
     * changes and messages to our webhook, and returns its first QR code.
     * It asks the phone for the full history on linking (`syncFullHistory`):
     * how far back it goes is up to the phone. Given the
     * number (digits with the country code), the instance also hands out a
     * pairing code to link it by phone number instead of the QR code.
     *
     * @throws EvolutionApiException
     */
    public function createInstance(string $instanceName, ?string $number = null): ?EvolutionQrCode
    {
        $response = $this->send('createInstance', fn (PendingRequest $request): Response => $request->post('/instance/create', array_filter([
            'instanceName' => $instanceName,
            'number' => $number,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
            'syncFullHistory' => true,
            'webhook' => $this->webhook(),
        ], fn (mixed $value): bool => $value !== null)));

        return EvolutionQrCode::fromPayload($this->arrayOrNull($response->json('qrcode')));
    }

    /**
     * Points the instance's webhook at us with the current WEBHOOK_EVENTS,
     * for the instances created before an event was added.
     *
     * @throws EvolutionApiException
     */
    public function setWebhook(string $instanceName): void
    {
        $this->send('setWebhook', fn (PendingRequest $request): Response => $request->post('/webhook/set/'.rawurlencode($instanceName), [
            'webhook' => $this->webhook(),
        ]));
    }

    /**
     * Starts (or keeps) the connection and returns the current QR code;
     * null when the number is already connected. Given the number, a closed
     * instance restarts handing out pairing codes (one that is still
     * connecting keeps the way it was started).
     *
     * @throws EvolutionApiException
     */
    public function connect(string $instanceName, ?string $number = null): ?EvolutionQrCode
    {
        $response = $this->send('connect', fn (PendingRequest $request): Response => $request->get(
            '/instance/connect/'.rawurlencode($instanceName),
            $number === null ? [] : ['number' => $number],
        ));

        return EvolutionQrCode::fromPayload($this->arrayOrNull($response->json()));
    }

    /**
     * The instance's connection state as the Evolution API keeps it
     * (`open`, `connecting` or `close`); null when it does not know the
     * instance.
     *
     * @throws EvolutionApiException
     */
    public function connectionState(string $instanceName): ?string
    {
        $response = $this->send('connectionState', fn (PendingRequest $request): Response => $request->get('/instance/connectionState/'.rawurlencode($instanceName)));

        $state = $response->json('instance.state');

        return is_string($state) && $state !== '' ? $state : null;
    }

    /**
     * Drops and reopens the instance's connection to WhatsApp, keeping the
     * number linked (no new QR code).
     *
     * @throws EvolutionApiException
     */
    public function restart(string $instanceName): void
    {
        $this->send('restart', fn (PendingRequest $request): Response => $request->post('/instance/restart/'.rawurlencode($instanceName)));
    }

    /**
     * Unlinks the number from the instance.
     *
     * @throws EvolutionApiException
     */
    public function logout(string $instanceName): void
    {
        $this->send('logout', fn (PendingRequest $request): Response => $request->delete('/instance/logout/'.rawurlencode($instanceName)));
    }

    /**
     * @throws EvolutionApiException
     */
    public function deleteInstance(string $instanceName): void
    {
        $this->send('deleteInstance', fn (PendingRequest $request): Response => $request->delete('/instance/delete/'.rawurlencode($instanceName)));
    }

    /**
     * The people the number knows (`@s.whatsapp.net` JIDs only: groups come
     * from `fetchGroups()`), with their push name and the URL of their
     * profile picture (on WhatsApp's CDN, valid for a few weeks).
     *
     * @return list<array{jid: string, name: string|null, picture: string|null}>
     *
     * @throws EvolutionApiException
     */
    public function fetchContacts(string $instanceName): array
    {
        $response = $this->send('fetchContacts', fn (PendingRequest $request): Response => $request->post('/chat/findContacts/'.rawurlencode($instanceName), ['where' => (object) []]), $this->syncTimeout);

        $contacts = [];

        foreach ($this->listOf($response) as $contact) {
            $jid = $contact['remoteJid'] ?? null;

            if (is_string($jid) && str_ends_with($jid, '@s.whatsapp.net')) {
                $contacts[] = ['jid' => $jid, 'name' => $this->nameOrNull($contact['pushName'] ?? null), 'picture' => $this->nameOrNull($contact['profilePicUrl'] ?? null)];
            }
        }

        return $contacts;
    }

    /**
     * The groups the number takes part in, with their subject and, when
     * WhatsApp gives it, the URL of their picture.
     *
     * @return list<array{jid: string, name: string|null, picture: string|null}>
     *
     * @throws EvolutionApiException
     */
    public function fetchGroups(string $instanceName): array
    {
        $response = $this->send('fetchGroups', fn (PendingRequest $request): Response => $request->get('/group/fetchAllGroups/'.rawurlencode($instanceName), ['getParticipants' => 'false']), $this->syncTimeout);

        $groups = [];

        foreach ($this->listOf($response) as $group) {
            $jid = $group['id'] ?? null;

            if (is_string($jid) && str_ends_with($jid, '@g.us')) {
                $groups[] = ['jid' => $jid, 'name' => $this->nameOrNull($group['subject'] ?? null), 'picture' => $this->nameOrNull($group['pictureUrl'] ?? null)];
            }
        }

        return $groups;
    }

    /**
     * Tells WhatsApp the given received messages were read (the blue ticks
     * on the sender's phone): `POST /chat/markMessageAsRead`.
     *
     * @param  list<array{id: string, remoteJid: string, participant?: string|null}>  $messages
     *
     * @throws EvolutionApiException
     */
    public function markRead(string $instanceName, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->send('markMessageAsRead', fn (PendingRequest $request): Response => $request->post('/chat/markMessageAsRead/'.rawurlencode($instanceName), [
            'readMessages' => array_map(fn (array $message): array => array_filter([
                'id' => $message['id'],
                'fromMe' => false,
                'remoteJid' => $message['remoteJid'],
                'participant' => $message['participant'] ?? null,
            ], fn (mixed $value): bool => $value !== null), $messages),
        ]));
    }

    /**
     * Shows the number's presence in one chat (`POST /chat/sendPresence`):
     * `composing` ("typing…"), `recording` or `paused`, for `$milliseconds`,
     * after which the Evolution API sends `paused` by itself. It also
     * subscribes to the chat's presence, so its `presence.update`s come.
     * The Evolution API answers only once the time is over: `$timeout`
     * (seconds) may be shorter, the presence going on regardless.
     *
     * @throws EvolutionApiException
     */
    public function sendPresence(string $instanceName, string $jid, string $presence, int $milliseconds, ?int $timeout = null): void
    {
        $this->send('sendPresence', fn (PendingRequest $request): Response => $request->post('/chat/sendPresence/'.rawurlencode($instanceName), [
            'number' => $jid,
            'presence' => $presence,
            'delay' => $milliseconds,
        ]), $timeout);
    }

    /**
     * The push name of one contact, or null when WhatsApp has none.
     *
     * @throws EvolutionApiException
     */
    public function contactNameOf(string $instanceName, string $jid): ?string
    {
        $response = $this->send('fetchContacts', fn (PendingRequest $request): Response => $request->post('/chat/findContacts/'.rawurlencode($instanceName), [
            'where' => ['remoteJid' => $jid],
        ]));

        foreach ($this->listOf($response) as $contact) {
            if (($contact['remoteJid'] ?? null) === $jid) {
                return $this->nameOrNull($contact['pushName'] ?? null);
            }
        }

        return null;
    }

    /**
     * The subject and picture URL of one group (`GET /group/findGroupInfos`).
     *
     * @return array{name: string|null, picture: string|null}
     *
     * @throws EvolutionApiException
     */
    public function groupInfoOf(string $instanceName, string $jid): array
    {
        $response = $this->send('groupInfo', fn (PendingRequest $request): Response => $request->get('/group/findGroupInfos/'.rawurlencode($instanceName), [
            'groupJid' => $jid,
        ]));

        return [
            'name' => $this->nameOrNull($response->json('subject')),
            'picture' => $this->nameOrNull($response->json('pictureUrl')),
        ];
    }

    /**
     * The URL of the profile picture of a contact or group (on WhatsApp's
     * CDN, valid for a few weeks), or null when it has none or hides it.
     *
     * @throws EvolutionApiException
     */
    public function profilePictureOf(string $instanceName, string $jid): ?string
    {
        $response = $this->send('fetchProfilePictureUrl', fn (PendingRequest $request): Response => $request->post('/chat/fetchProfilePictureUrl/'.rawurlencode($instanceName), [
            'number' => $jid,
        ]));

        return $this->nameOrNull($response->json('profilePictureUrl'));
    }

    /**
     * The group an invite code leads to (the code of a
     * `https://chat.whatsapp.com/<code>` link), with its subject; null when
     * the answer carries no group id. An invalid or revoked code answers
     * 404 or 400.
     *
     * @return array{jid: string, name: string|null}|null
     *
     * @throws EvolutionApiException
     */
    public function groupInviteInfo(string $instanceName, string $inviteCode): ?array
    {
        $response = $this->send('groupInviteInfo', fn (PendingRequest $request): Response => $request->get(
            '/group/inviteInfo/'.rawurlencode($instanceName),
            ['inviteCode' => $inviteCode],
        ), $this->syncTimeout);

        $jid = $response->json('id');

        if (! is_string($jid) || ! str_ends_with($jid, '@g.us')) {
            return null;
        }

        return ['jid' => $jid, 'name' => $this->nameOrNull($response->json('subject'))];
    }

    /**
     * Makes the number join the group of an invite code and returns the
     * group's JID (null when the answer carries none).
     *
     * @throws EvolutionApiException
     */
    public function acceptGroupInvite(string $instanceName, string $inviteCode): ?string
    {
        $response = $this->send('acceptGroupInvite', fn (PendingRequest $request): Response => $request->get(
            '/group/acceptInviteCode/'.rawurlencode($instanceName),
            ['inviteCode' => $inviteCode],
        ), $this->syncTimeout);

        $jid = $response->json('groupJid');

        return is_string($jid) && str_ends_with($jid, '@g.us') ? $jid : null;
    }

    /**
     * Sends a plain text message to a number or a JID and returns the
     * WhatsApp message id.
     *
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context  see context()
     *
     * @throws EvolutionApiException
     */
    public function sendText(string $instanceName, string $number, string $text, array $context = []): string
    {
        $response = $this->send('sendText', fn (PendingRequest $request): Response => $request->post('/message/sendText/'.rawurlencode($instanceName), [
            'number' => $number,
            'text' => $text,
            ...$this->context($context),
        ]));

        return (string) $response->json('key.id');
    }

    /**
     * Asks the phone for the full history the next time the number is
     * linked (`syncFullHistory`), keeping the instance's other settings:
     * `POST /settings/set` replaces them all, so they are read first.
     * WhatsApp hands the history over on linking only.
     *
     * @throws EvolutionApiException
     */
    public function enableFullHistory(string $instanceName): void
    {
        $response = $this->send('findSettings', fn (PendingRequest $request): Response => $request->get('/settings/find/'.rawurlencode($instanceName)));
        $current = $this->arrayOrNull($response->json()) ?? [];

        $settings = [
            'rejectCall' => (bool) ($current['rejectCall'] ?? false),
            'msgCall' => is_string($current['msgCall'] ?? null) && $current['msgCall'] !== '' ? $current['msgCall'] : null,
            'groupsIgnore' => (bool) ($current['groupsIgnore'] ?? false),
            'alwaysOnline' => (bool) ($current['alwaysOnline'] ?? false),
            'readMessages' => (bool) ($current['readMessages'] ?? false),
            'readStatus' => (bool) ($current['readStatus'] ?? false),
            'syncFullHistory' => true,
        ];

        $this->send('setSettings', fn (PendingRequest $request): Response => $request->post(
            '/settings/set/'.rawurlencode($instanceName),
            array_filter($settings, fn (mixed $value): bool => $value !== null),
        ));
    }

    /**
     * A page of the messages the Evolution API keeps for the instance,
     * newest first (Baileys `WAMessage`s, as the webhooks send them), and
     * how many pages there are. `$where` narrows them by their key, e.g.
     * `['remoteJid' => $jid]` for one chat, or `['remoteJidAlt' => $jid]`
     * for the messages of a contact kept under its `@lid`.
     *
     * @param  array<string, string>  $where
     * @return array{records: list<array<string, mixed>>, pages: int}
     *
     * @throws EvolutionApiException
     */
    public function findMessages(string $instanceName, int $page, int $perPage, array $where = []): array
    {
        $response = $this->send('findMessages', fn (PendingRequest $request): Response => $request->post('/chat/findMessages/'.rawurlencode($instanceName), [
            'where' => $where === [] ? (object) [] : ['key' => $where],
            'page' => $page,
            'offset' => $perPage,
        ]), $this->syncTimeout);

        $records = $response->json('messages.records');

        return [
            'records' => is_array($records) ? array_values(array_filter($records, is_array(...))) : [],
            'pages' => (int) $response->json('messages.pages', 0),
        ];
    }

    /**
     * Points the instance's WhatsApp connection at a proxy, or back to a
     * direct one with null (`POST /proxy/set`). The Evolution API first
     * checks that the proxy answers and changes the IP the instance goes
     * out with, and refuses it (400) otherwise. It takes effect when the
     * connection is opened again.
     *
     * @param  array{protocol: string, host: string, port: int, username: string|null, password: string|null}|null  $proxy
     *
     * @throws EvolutionApiException
     */
    public function setProxy(string $instanceName, ?array $proxy): void
    {
        $this->send('setProxy', fn (PendingRequest $request): Response => $request->post('/proxy/set/'.rawurlencode($instanceName), $proxy === null
            ? ['enabled' => false, 'host' => '', 'port' => '', 'protocol' => 'http']
            : array_filter([
                'enabled' => true,
                'host' => $proxy['host'],
                'port' => (string) $proxy['port'],
                'protocol' => $proxy['protocol'],
                'username' => $proxy['username'],
                'password' => $proxy['password'],
            ], fn (mixed $value): bool => $value !== null && $value !== '')), $this->syncTimeout);
    }

    /**
     * The file of a media message the Evolution API keeps, by its WhatsApp
     * id (`POST /chat/getBase64FromMediaMessage`), decoded. With
     * `$convertAudio`, an audio comes converted by the Evolution API from
     * WhatsApp's Ogg/Opus to MP4/AAC (`audio/mp4`), which every browser
     * plays, Safari included.
     *
     * @return array{content: string, mime: string|null, name: string|null}
     *
     * @throws EvolutionApiException when it cannot be fetched, or comes back empty
     */
    public function mediaOf(string $instanceName, string $messageId, bool $convertAudio = false): array
    {
        $response = $this->send('getBase64FromMediaMessage', fn (PendingRequest $request): Response => $request->post('/chat/getBase64FromMediaMessage/'.rawurlencode($instanceName), [
            'message' => ['key' => ['id' => $messageId]],
            'convertToMp4' => $convertAudio,
        ]), $this->syncTimeout);

        $content = base64_decode((string) $response->json('base64'), true);

        if ($content === false || $content === '') {
            throw EvolutionApiException::fromResponse('getBase64FromMediaMessage', $response);
        }

        return [
            'content' => $content,
            'mime' => $this->nameOrNull($response->json('mimetype')),
            'name' => $this->nameOrNull($response->json('fileName')),
        ];
    }

    /**
     * Sends a file (`POST /message/sendMedia`) as an `image`, `video` or
     * `document`, with an optional caption, and returns the WhatsApp
     * message id.
     *
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context  see context()
     *
     * @throws EvolutionApiException
     */
    public function sendMedia(string $instanceName, string $number, string $mediaType, string $mime, string $content, ?string $caption = null, ?string $fileName = null, array $context = []): string
    {
        $response = $this->send('sendMedia', fn (PendingRequest $request): Response => $request->post('/message/sendMedia/'.rawurlencode($instanceName), array_filter([
            'number' => $number,
            'mediatype' => $mediaType,
            'mimetype' => $mime,
            'media' => base64_encode($content),
            'caption' => filled($caption) ? $caption : null,
            'fileName' => $fileName,
            ...$this->context($context),
        ], fn (mixed $value): bool => $value !== null)), $this->syncTimeout);

        return (string) $response->json('key.id');
    }

    /**
     * Reacts to a message with an emoji (`POST /message/sendReaction`); an
     * empty one removes the reaction. `$key` is the message's WhatsApp key:
     * `remoteJid`, `fromMe`, `id` and, in a group, the `participant` who
     * sent it.
     *
     * @param  array{remoteJid: string, fromMe: bool, id: string, participant?: string}  $key
     *
     * @throws EvolutionApiException
     */
    public function sendReaction(string $instanceName, array $key, string $emoji): void
    {
        $this->send('sendReaction', fn (PendingRequest $request): Response => $request->post('/message/sendReaction/'.rawurlencode($instanceName), [
            'key' => $key,
            'reaction' => $emoji,
        ]));
    }

    /**
     * Sends an audio as a voice note (`POST /message/sendWhatsAppAudio`)
     * and returns the WhatsApp message id. The Evolution API sends no quote
     * nor mention with one.
     *
     * @throws EvolutionApiException
     */
    public function sendAudio(string $instanceName, string $number, string $content): string
    {
        $response = $this->send('sendWhatsAppAudio', fn (PendingRequest $request): Response => $request->post('/message/sendWhatsAppAudio/'.rawurlencode($instanceName), [
            'number' => $number,
            'audio' => base64_encode($content),
        ]), $this->syncTimeout);

        return (string) $response->json('key.id');
    }

    /**
     * Sends a poll (`POST /message/sendPoll`): `$selectable` 1 for a single
     * choice, 0 for any number. Returns the WhatsApp message id.
     *
     * @param  list<string>  $options  2 to 12, all different
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context  see context()
     *
     * @throws EvolutionApiException
     */
    public function sendPoll(string $instanceName, string $number, string $name, array $options, int $selectable, array $context = []): string
    {
        $response = $this->send('sendPoll', fn (PendingRequest $request): Response => $request->post('/message/sendPoll/'.rawurlencode($instanceName), [
            'number' => $number,
            'name' => $name,
            'selectableCount' => $selectable,
            'values' => $options,
            ...$this->context($context),
        ]));

        return (string) $response->json('key.id');
    }

    /**
     * Sends a location (`POST /message/sendLocation`; its name and address
     * are required by the Evolution API, so they go as '' when unknown).
     *
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context  see context()
     *
     * @throws EvolutionApiException
     */
    public function sendLocation(string $instanceName, string $number, float $latitude, float $longitude, ?string $name = null, ?string $address = null, array $context = []): string
    {
        $response = $this->send('sendLocation', fn (PendingRequest $request): Response => $request->post('/message/sendLocation/'.rawurlencode($instanceName), [
            'number' => $number,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => (string) $name,
            'address' => (string) $address,
            ...$this->context($context),
        ]));

        return (string) $response->json('key.id');
    }

    /**
     * Sends contact cards (`POST /message/sendContact`): one goes as a
     * `contactMessage`, several as a `contactsArrayMessage`. `wuid` goes as
     * digits, or the Evolution API writes a malformed `waid` in the vCard.
     * No quote: the Evolution API sends none with contacts.
     *
     * @param  list<array{name: string, number: string}>  $contacts  the number with its country code
     *
     * @throws EvolutionApiException
     */
    public function sendContacts(string $instanceName, string $number, array $contacts): string
    {
        $response = $this->send('sendContact', fn (PendingRequest $request): Response => $request->post('/message/sendContact/'.rawurlencode($instanceName), [
            'number' => $number,
            'contact' => array_map(function (array $contact): array {
                $digits = (string) preg_replace('/\D/', '', $contact['number']);

                return ['fullName' => $contact['name'], 'phoneNumber' => '+'.$digits, 'wuid' => $digits];
            }, $contacts),
        ]));

        return (string) $response->json('key.id');
    }

    /**
     * Sends an image as a sticker (`POST /message/sendSticker`), as base64:
     * a WebP goes as it is (`notConvertSticker`), any other image is turned
     * into a still WebP by the Evolution API.
     *
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context  see context()
     *
     * @throws EvolutionApiException
     */
    public function sendSticker(string $instanceName, string $number, string $mime, string $content, array $context = []): string
    {
        $response = $this->send('sendSticker', fn (PendingRequest $request): Response => $request->post('/message/sendSticker/'.rawurlencode($instanceName), [
            'number' => $number,
            'sticker' => base64_encode($content),
            'notConvertSticker' => $mime === 'image/webp',
            ...$this->context($context),
        ]), $this->syncTimeout);

        return (string) $response->json('key.id');
    }

    /**
     * Deletes a message for everyone (`DELETE /chat/deleteMessageForEveryone`),
     * by its WhatsApp key (`participant` for someone else's in a group,
     * which only an admin may delete).
     *
     * @param  array{remoteJid: string, fromMe: bool, id: string, participant?: string}  $key
     *
     * @throws EvolutionApiException
     */
    public function deleteForEveryone(string $instanceName, array $key): void
    {
        $this->send('deleteMessageForEveryone', fn (PendingRequest $request): Response => $request->delete('/chat/deleteMessageForEveryone/'.rawurlencode($instanceName), $key));
    }

    /**
     * Edits the text (or caption) of a message the number sent
     * (`POST /chat/updateMessage`). `number` is the chat's JID as it is, so
     * the Evolution API finds the message under the same `remoteJid`.
     *
     * @param  array{remoteJid: string, fromMe: bool, id: string, participant?: string}  $key
     *
     * @throws EvolutionApiException
     */
    public function editMessage(string $instanceName, array $key, string $text): void
    {
        $this->send('updateMessage', fn (PendingRequest $request): Response => $request->post('/chat/updateMessage/'.rawurlencode($instanceName), [
            'number' => $key['remoteJid'],
            'key' => ['id' => $key['id'], 'remoteJid' => $key['remoteJid'], 'fromMe' => $key['fromMe']],
            'text' => $text,
        ]));
    }

    /**
     * Archives or unarchives a chat on WhatsApp (`POST /chat/archiveChat`),
     * by its latest message.
     *
     * @param  array{key: array{remoteJid: string, fromMe: bool, id: string}, messageTimestamp: int}  $lastMessage
     *
     * @throws EvolutionApiException
     */
    public function archiveChat(string $instanceName, string $jid, bool $archive, array $lastMessage): void
    {
        $this->send('archiveChat', fn (PendingRequest $request): Response => $request->post('/chat/archiveChat/'.rawurlencode($instanceName), [
            'archive' => $archive,
            'lastMessage' => $lastMessage,
            'chat' => $jid,
        ]));
    }

    /**
     * Marks a chat as unread on WhatsApp (`POST /chat/markChatUnread`), by
     * its latest message. Reading it again is a read receipt (markRead()).
     *
     * @param  array{key: array{remoteJid: string, fromMe: bool, id: string}, messageTimestamp: int}  $lastMessage
     *
     * @throws EvolutionApiException
     */
    public function markChatUnread(string $instanceName, string $jid, array $lastMessage): void
    {
        $this->send('markChatUnread', fn (PendingRequest $request): Response => $request->post('/chat/markChatUnread/'.rawurlencode($instanceName), [
            'lastMessage' => $lastMessage,
            'chat' => $jid,
        ]));
    }

    /**
     * Blocks or unblocks a contact (`POST /chat/updateBlockStatus`).
     *
     * @throws EvolutionApiException
     */
    public function updateBlockStatus(string $instanceName, string $jid, bool $block): void
    {
        $this->send('updateBlockStatus', fn (PendingRequest $request): Response => $request->post('/chat/updateBlockStatus/'.rawurlencode($instanceName), [
            'number' => $jid,
            'status' => $block ? 'block' : 'unblock',
        ]));
    }

    /**
     * Creates a group with the numbers (digits with the country code) and
     * returns its JID; WhatsApp leaves out, without a word, the numbers
     * that are not on it.
     *
     * @param  list<string>  $numbers
     *
     * @throws EvolutionApiException
     */
    public function createGroup(string $instanceName, string $subject, array $numbers, ?string $description = null): string
    {
        $response = $this->send('createGroup', fn (PendingRequest $request): Response => $request->post('/group/create/'.rawurlencode($instanceName), array_filter([
            'subject' => $subject,
            'participants' => $numbers,
            'description' => filled($description) ? $description : null,
        ], fn (mixed $value): bool => $value !== null)), $this->syncTimeout);

        $jid = $response->json('id');

        if (! is_string($jid) || ! str_ends_with($jid, '@g.us')) {
            throw EvolutionApiException::fromResponse('createGroup', $response);
        }

        return $jid;
    }

    /**
     * The members of a group: the phone JID when WhatsApp gives one (else
     * the `@lid`), the `@lid`, whether an admin (`admin`, `superadmin`) and
     * the name WhatsApp knows.
     *
     * @return list<array{jid: string, lid: string|null, admin: string|null, name: string|null}>
     *
     * @throws EvolutionApiException
     */
    public function groupParticipants(string $instanceName, string $groupJid): array
    {
        $response = $this->send('groupParticipants', fn (PendingRequest $request): Response => $request->get('/group/participants/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
        ]), $this->syncTimeout);

        $participants = [];

        foreach (is_array($response->json('participants')) ? $response->json('participants') : [] as $participant) {
            if (! is_array($participant)) {
                continue;
            }

            $id = $this->nameOrNull($participant['id'] ?? null);
            $phone = $this->nameOrNull($participant['phoneNumber'] ?? null);
            $jid = $phone !== null && str_ends_with($phone, '@s.whatsapp.net') ? $phone : $id;

            if ($jid === null) {
                continue;
            }

            $lid = $this->nameOrNull($participant['lid'] ?? null) ?? ($id !== null && str_ends_with($id, '@lid') ? $id : null);

            $participants[] = [
                'jid' => $jid,
                'lid' => $lid,
                'admin' => in_array($participant['admin'] ?? null, ['admin', 'superadmin'], true) ? $participant['admin'] : null,
                'name' => $this->nameOrNull($participant['name'] ?? null),
            ];
        }

        return $participants;
    }

    /**
     * Adds, removes, promotes to admin or demotes members of a group
     * (`POST /group/updateParticipant`); returns, by JID, whether WhatsApp
     * did it (it answers 201 even when one of them failed).
     *
     * @param  'add'|'remove'|'promote'|'demote'  $action
     * @param  list<string>  $participants  numbers or JIDs
     * @return array<string, bool>
     *
     * @throws EvolutionApiException
     */
    public function updateGroupParticipants(string $instanceName, string $groupJid, string $action, array $participants): array
    {
        $response = $this->send('updateParticipant', fn (PendingRequest $request): Response => $request->post('/group/updateParticipant/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
            'action' => $action,
            'participants' => $participants,
        ]), $this->syncTimeout);

        $results = [];

        foreach (is_array($response->json('updateParticipants')) ? $response->json('updateParticipants') : [] as $result) {
            $jid = is_array($result) ? ($result['jid'] ?? null) : null;

            if (is_string($jid)) {
                $results[$jid] = (string) ($result['status'] ?? '') === '200';
            }
        }

        return $results;
    }

    /**
     * Renames a group (`POST /group/updateGroupSubject`).
     *
     * @throws EvolutionApiException
     */
    public function updateGroupSubject(string $instanceName, string $groupJid, string $subject): void
    {
        $this->send('updateGroupSubject', fn (PendingRequest $request): Response => $request->post('/group/updateGroupSubject/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
            'subject' => $subject,
        ]));
    }

    /**
     * Changes a group's description (`POST /group/updateGroupDescription`).
     *
     * @throws EvolutionApiException
     */
    public function updateGroupDescription(string $instanceName, string $groupJid, string $description): void
    {
        $this->send('updateGroupDescription', fn (PendingRequest $request): Response => $request->post('/group/updateGroupDescription/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
            'description' => $description,
        ]));
    }

    /**
     * Changes a group's picture (`POST /group/updateGroupPicture`), as base64.
     *
     * @throws EvolutionApiException
     */
    public function updateGroupPicture(string $instanceName, string $groupJid, string $content): void
    {
        $this->send('updateGroupPicture', fn (PendingRequest $request): Response => $request->post('/group/updateGroupPicture/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
            'image' => base64_encode($content),
        ]), $this->syncTimeout);
    }

    /**
     * Who may send messages (`announcement`: admins only;
     * `not_announcement`: everyone) and edit the group's info (`locked`:
     * admins only; `unlocked`: everyone): `POST /group/updateSetting`.
     *
     * @param  'announcement'|'not_announcement'|'locked'|'unlocked'  $setting
     *
     * @throws EvolutionApiException
     */
    public function updateGroupSetting(string $instanceName, string $groupJid, string $setting): void
    {
        $this->send('updateGroupSetting', fn (PendingRequest $request): Response => $request->post('/group/updateSetting/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
            'action' => $setting,
        ]));
    }

    /**
     * Turns disappearing messages of a group on (after 86400, 604800 or
     * 7776000 seconds) or off (0): `POST /group/toggleEphemeral`. The
     * Evolution API has no way to do it in a one-to-one chat.
     *
     * @throws EvolutionApiException
     */
    public function toggleGroupEphemeral(string $instanceName, string $groupJid, int $seconds): void
    {
        $this->send('toggleEphemeral', fn (PendingRequest $request): Response => $request->post('/group/toggleEphemeral/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
            'expiration' => $seconds,
        ]));
    }

    /**
     * The link to join a group (`GET /group/inviteCode`); with `$revoke`,
     * the current one stops working first (`POST /group/revokeInviteCode`).
     * Only an admin gets one.
     *
     * @throws EvolutionApiException
     */
    public function groupInviteLink(string $instanceName, string $groupJid, bool $revoke = false): string
    {
        if ($revoke) {
            $this->send('revokeInviteCode', fn (PendingRequest $request): Response => $request->post('/group/revokeInviteCode/'.rawurlencode($instanceName), [
                'groupJid' => $groupJid,
            ]));
        }

        $response = $this->send('inviteCode', fn (PendingRequest $request): Response => $request->get('/group/inviteCode/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
        ]));

        $url = $this->nameOrNull($response->json('inviteUrl'));
        $code = $this->nameOrNull($response->json('inviteCode'));

        return $url ?? ($code !== null ? 'https://chat.whatsapp.com/'.$code : throw EvolutionApiException::fromResponse('inviteCode', $response));
    }

    /**
     * Makes the number leave a group (`DELETE /group/leaveGroup`).
     *
     * @throws EvolutionApiException
     */
    public function leaveGroup(string $instanceName, string $groupJid): void
    {
        $this->send('leaveGroup', fn (PendingRequest $request): Response => $request->delete('/group/leaveGroup/'.rawurlencode($instanceName).'?'.http_build_query(['groupJid' => $groupJid])));
    }

    /**
     * The subject, description and settings of one group
     * (`GET /group/findGroupInfos`).
     *
     * @return array{name: string|null, description: string|null, announce: bool, restrict: bool, ephemeral: int}
     *
     * @throws EvolutionApiException
     */
    public function groupDetailsOf(string $instanceName, string $groupJid): array
    {
        $response = $this->send('groupInfo', fn (PendingRequest $request): Response => $request->get('/group/findGroupInfos/'.rawurlencode($instanceName), [
            'groupJid' => $groupJid,
        ]));

        return [
            'name' => $this->nameOrNull($response->json('subject')),
            'description' => $this->nameOrNull($response->json('desc')),
            'announce' => (bool) $response->json('announce', false),
            'restrict' => (bool) $response->json('restrict', false),
            'ephemeral' => (int) $response->json('ephemeralDuration', 0),
        ];
    }

    /**
     * The quote and mentions of a message, as the send endpoints take them:
     * `quoted` is `{key: {id, remoteJid, fromMe, participant?}, message?}`
     * (without `message`, the Evolution API looks the message up, and
     * sends no quote when it does not keep it); `mentioned` are numbers or
     * JIDs, which WhatsApp only honours in a group.
     *
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context
     * @return array<string, mixed>
     */
    private function context(array $context): array
    {
        return array_filter([
            'quoted' => $context['quoted'] ?? null,
            'mentioned' => ($context['mentioned'] ?? []) !== [] ? array_values($context['mentioned']) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{enabled: bool, url: string, byEvents: bool, base64: bool, headers: array<string, string>, events: list<string>}
     */
    private function webhook(): array
    {
        return [
            'enabled' => true,
            'url' => $this->webhookUrl ?: route('webhooks.evolution'),
            'byEvents' => false,
            'base64' => false,
            'headers' => [self::WEBHOOK_SECRET_HEADER => (string) $this->webhookSecret],
            'events' => self::WEBHOOK_EVENTS,
        ];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  int|null  $timeout  seconds to wait for the answer; null for `timeout`
     *
     * @throws EvolutionApiException
     */
    private function send(string $action, callable $call, ?int $timeout = null): Response
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders(['apikey' => (string) $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout($this->timeout);

        try {
            $response = $call($request);
        } catch (ConnectionException $exception) {
            throw EvolutionApiException::unreachable($action, $exception);
        }

        if ($response->status() >= 400) {
            throw EvolutionApiException::fromResponse($action, $response);
        }

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(Response $response): array
    {
        $items = $response->json();

        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function nameOrNull(mixed $name): ?string
    {
        return is_string($name) && mb_trim($name) !== '' ? mb_trim($name) : null;
    }
}
