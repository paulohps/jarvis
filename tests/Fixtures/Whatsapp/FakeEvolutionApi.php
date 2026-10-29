<?php

namespace Tests\Fixtures\Whatsapp;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Evolution API over `Http::fake()`, so no test reaches the
 * network: every endpoint `EvolutionApiClient` calls answers like the v2 API
 * does, and any other request throws (`preventStrayRequests`).
 *
 * Pass `$overrides` by client method name (`createInstance`, `connect`,
 * `connectionState`, `restart`, `logout`, `deleteInstance`, `fetchContacts`, `fetchGroups`, `groupInviteInfo`, `acceptGroupInvite`,
 * `sendText`, `setWebhook`, `findSettings`, `setSettings`, `findMessages`,
 * `mediaOf`, `sendMedia`, `sendAudio`, and the rest of ENDPOINTS) to
 * answer something else, an error included.
 */
final class FakeEvolutionApi
{
    public const string CREATED_QR = 'data:image/png;base64,Q1JFQVRFRA==';

    public const string RENEWED_QR = 'data:image/png;base64,UkVORVdFRA==';

    public const string PAIRING_CODE = 'WZYEH1YY';

    public const string INVITE_GROUP_JID = '120363000000000077@g.us';

    public const string MESSAGE_ID = '3EB0A1B2C3D4E5F6';

    public const string CREATED_GROUP_JID = '120363000000000099@g.us';

    public const string INVITE_CODE = 'Fam1lyInv1te';

    /**
     * The file `mediaOf` hands out: the bytes of a 1×1 PNG.
     */
    public const string PICTURE_URL = 'https://pps.whatsapp.net/v/t61/ana.jpg?oe=6A000000';

    public const string MEDIA_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

    /**
     * The endpoint of each client method, below the base URL.
     *
     * @var array<string, string>
     */
    private const array ENDPOINTS = [
        'createInstance' => '/instance/create',
        'connect' => '/instance/connect/*',
        'connectionState' => '/instance/connectionState/*',
        'restart' => '/instance/restart/*',
        'logout' => '/instance/logout/*',
        'deleteInstance' => '/instance/delete/*',
        'fetchContacts' => '/chat/findContacts/*',
        'fetchGroups' => '/group/fetchAllGroups/*',
        'groupInviteInfo' => '/group/inviteInfo/*',
        'acceptGroupInvite' => '/group/acceptInviteCode/*',
        'sendText' => '/message/sendText/*',
        'setWebhook' => '/webhook/set/*',
        'findSettings' => '/settings/find/*',
        'setSettings' => '/settings/set/*',
        'findMessages' => '/chat/findMessages/*',
        'mediaOf' => '/chat/getBase64FromMediaMessage/*',
        'sendMedia' => '/message/sendMedia/*',
        'sendAudio' => '/message/sendWhatsAppAudio/*',
        'sendReaction' => '/message/sendReaction/*',
        'setProxy' => '/proxy/set/*',
        'profilePicture' => '/chat/fetchProfilePictureUrl/*',
        'groupInfo' => '/group/findGroupInfos/*',
        'markRead' => '/chat/markMessageAsRead/*',
        'sendPresence' => '/chat/sendPresence/*',
        'sendPoll' => '/message/sendPoll/*',
        'sendLocation' => '/message/sendLocation/*',
        'sendContact' => '/message/sendContact/*',
        'sendSticker' => '/message/sendSticker/*',
        'deleteForEveryone' => '/chat/deleteMessageForEveryone/*',
        'editMessage' => '/chat/updateMessage/*',
        'archiveChat' => '/chat/archiveChat/*',
        'markChatUnread' => '/chat/markChatUnread/*',
        'updateBlockStatus' => '/chat/updateBlockStatus/*',
        'createGroup' => '/group/create/*',
        'groupParticipants' => '/group/participants/*',
        'updateParticipant' => '/group/updateParticipant/*',
        'updateGroupSubject' => '/group/updateGroupSubject/*',
        'updateGroupDescription' => '/group/updateGroupDescription/*',
        'updateGroupPicture' => '/group/updateGroupPicture/*',
        'updateGroupSetting' => '/group/updateSetting/*',
        'toggleEphemeral' => '/group/toggleEphemeral/*',
        'inviteCode' => '/group/inviteCode/*',
        'revokeInviteCode' => '/group/revokeInviteCode/*',
        'leaveGroup' => '/group/leaveGroup/*',
    ];

    /**
     * @param  array<string, PromiseInterface|Closure|array<mixed>>  $overrides  by client method
     */
    public static function install(array $overrides = []): void
    {
        $responses = [...self::defaults(), ...$overrides];
        $baseUrl = rtrim((string) config('services.evolution.url'), '/');
        $fakes = [];

        foreach (self::ENDPOINTS as $method => $path) {
            $response = $responses[$method];
            $fakes[$baseUrl.$path] = is_array($response) ? Http::response($response) : $response;
        }

        // Installing again starts over: the earlier stubs would answer first.
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake($fakes);
    }

    /**
     * The contacts `findContacts` returns by default: two people, plus a
     * group and a `@lid` entry the client must leave out.
     *
     * @return list<array<string, mixed>>
     */
    public static function contacts(): array
    {
        return [
            ['id' => 'c1', 'remoteJid' => '5511911110000@s.whatsapp.net', 'pushName' => 'Ana', 'profilePicUrl' => self::PICTURE_URL],
            ['id' => 'c2', 'remoteJid' => '5511922220000@s.whatsapp.net', 'pushName' => 'Bruno', 'profilePicUrl' => null],
            ['id' => 'c3', 'remoteJid' => '120363000000000001@g.us', 'pushName' => 'Family', 'profilePicUrl' => null],
            ['id' => 'c4', 'remoteJid' => '123456789@lid', 'pushName' => null, 'profilePicUrl' => null],
        ];
    }

    /**
     * The groups `fetchAllGroups` returns by default.
     *
     * @return list<array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            ['id' => '120363000000000001@g.us', 'subject' => 'Family', 'size' => 5],
        ];
    }

    /**
     * The members `group/participants` returns by default: the owner's
     * number (an admin), Ana and Bruno, the last known by its `@lid` too.
     *
     * @return list<array<string, mixed>>
     */
    public static function participants(): array
    {
        return [
            ['id' => '5511900000000@s.whatsapp.net', 'admin' => 'superadmin', 'name' => null],
            ['id' => '5511911110000@s.whatsapp.net', 'admin' => null, 'name' => 'Ana'],
            ['id' => '987654321@lid', 'phoneNumber' => '5511922220000@s.whatsapp.net', 'lid' => '987654321@lid', 'admin' => 'admin', 'name' => 'Bruno'],
        ];
    }

    /**
     * @return array<string, Closure|array<mixed>>
     */
    private static function defaults(): array
    {
        return [
            // Started with a `number`, the instance hands out a pairing code too.
            'createInstance' => fn (Request $request): PromiseInterface => Http::response([
                'instance' => ['instanceName' => $request['instanceName'], 'instanceId' => 'b7e1', 'integration' => 'WHATSAPP-BAILEYS', 'status' => 'connecting'],
                'hash' => 'instance-token',
                'qrcode' => ['pairingCode' => isset($request['number']) ? self::PAIRING_CODE : null, 'code' => '2@created', 'base64' => self::CREATED_QR, 'count' => 1],
            ]),
            'connect' => ['pairingCode' => null, 'code' => '2@renewed', 'base64' => self::RENEWED_QR, 'count' => 2],
            'connectionState' => ['instance' => ['instanceName' => 'jarvis', 'state' => 'open']],
            'restart' => ['instance' => ['instanceName' => 'jarvis', 'status' => 'connecting']],
            'logout' => ['status' => 'SUCCESS', 'error' => false, 'response' => ['message' => 'Instance logged out']],
            'deleteInstance' => ['status' => 'SUCCESS', 'error' => false, 'response' => ['message' => 'Instance deleted']],
            'fetchContacts' => self::contacts(),
            'fetchGroups' => self::groups(),
            'groupInviteInfo' => ['id' => self::INVITE_GROUP_JID, 'subject' => 'Book club', 'size' => 12],
            'acceptGroupInvite' => ['accepted' => true, 'groupJid' => self::INVITE_GROUP_JID],
            'sendText' => [
                'key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID],
                'status' => 'PENDING',
            ],
            'setWebhook' => ['webhook' => ['instanceName' => 'jarvis', 'webhook' => ['enabled' => true]]],
            'findSettings' => ['rejectCall' => true, 'msgCall' => 'No calls, please', 'groupsIgnore' => false, 'alwaysOnline' => false, 'readMessages' => false, 'readStatus' => false, 'syncFullHistory' => false],
            'setSettings' => ['settings' => ['instanceName' => 'jarvis']],
            'findMessages' => ['messages' => ['total' => 0, 'pages' => 0, 'currentPage' => 1, 'records' => []]],
            'mediaOf' => ['mediaType' => 'imageMessage', 'fileName' => 'photo.png', 'mimetype' => 'image/png', 'base64' => self::MEDIA_BASE64],
            'sendMedia' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID], 'status' => 'PENDING'],
            'sendAudio' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID], 'status' => 'PENDING'],
            'sendReaction' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => 'REACTION1'], 'status' => 'PENDING'],
            'setProxy' => ['instance' => ['instanceName' => 'jarvis'], 'proxy' => ['enabled' => true]],
            'groupInfo' => ['id' => '120363000000000001@g.us', 'subject' => 'Family', 'desc' => 'Sunday lunch', 'announce' => false, 'restrict' => true, 'ephemeralDuration' => 0, 'pictureUrl' => null, 'size' => 5],
            'markRead' => ['message' => 'Read messages', 'read' => 'success'],
            'sendPresence' => ['presence' => 'composing'],
            'profilePicture' => ['wuid' => '5511911110000@s.whatsapp.net', 'profilePictureUrl' => self::PICTURE_URL],
            'sendPoll' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID], 'status' => 'PENDING'],
            'sendLocation' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID], 'status' => 'PENDING'],
            'sendContact' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID], 'status' => 'PENDING'],
            'sendSticker' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => self::MESSAGE_ID], 'status' => 'PENDING'],
            'deleteForEveryone' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => 'REVOKE1'], 'message' => ['protocolMessage' => ['type' => 'REVOKE']]],
            'editMessage' => ['key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => 'EDIT1'], 'message' => ['protocolMessage' => ['type' => 'MESSAGE_EDIT']]],
            'archiveChat' => ['chatId' => '5511911110000@s.whatsapp.net', 'archived' => true],
            'markChatUnread' => ['chatId' => '5511911110000@s.whatsapp.net', 'markedChatUnread' => true],
            'updateBlockStatus' => ['block' => 'success'],
            'createGroup' => ['id' => self::CREATED_GROUP_JID, 'subject' => 'New group', 'participants' => []],
            'groupParticipants' => ['participants' => self::participants()],
            'updateParticipant' => fn (Request $request): PromiseInterface => Http::response(['updateParticipants' => array_map(
                fn (string $participant): array => ['status' => '200', 'jid' => str_contains($participant, '@') ? $participant : $participant.'@s.whatsapp.net', 'content' => []],
                (array) $request['participants'],
            )], 201),
            'updateGroupSubject' => ['update' => 'success'],
            'updateGroupDescription' => ['update' => 'success'],
            'updateGroupPicture' => ['update' => 'success'],
            'updateGroupSetting' => ['updateSetting' => true],
            'toggleEphemeral' => ['success' => true],
            'inviteCode' => ['inviteUrl' => 'https://chat.whatsapp.com/'.self::INVITE_CODE, 'inviteCode' => self::INVITE_CODE],
            'revokeInviteCode' => ['revoked' => true, 'inviteCode' => 'NEWCODE'],
            'leaveGroup' => ['groupJid' => '120363000000000001@g.us', 'leave' => true],
        ];
    }
}
