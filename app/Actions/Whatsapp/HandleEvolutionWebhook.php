<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappAccountStatusChanged;
use App\Events\WhatsappChatPresenceChanged;
use App\Events\WhatsappChatUpdated;
use App\Events\WhatsappQrCodeUpdated;
use App\Jobs\SyncWhatsappTargets;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReceipt;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappTarget;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\WhatsappAccountDisconnected;
use App\Services\Whatsapp\EvolutionQrCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Applies an Evolution API webhook to its account (US-7.1, US-7.2,
 * Workflow 9). The envelope is `{event, instance, data}`:
 *
 * - `qrcode.updated` (`data.qrcode.base64`, plus `data.qrcode.pairingCode`
 *   when linking by phone number): the new QR code goes to the owner's
 *   connection screen (`WhatsappQrCodeUpdated`).
 * - `connection.update` (`data.state`: `connecting`, `open`, `close`, or
 *   `refused` when the QR codes ran out): the account takes the status and
 *   the owner's screens are told (`WhatsappAccountStatusChanged`). Going
 *   `open` stamps `connected_at`, keeps the number (`data.wuid`) and queues
 *   `SyncWhatsappTargets`; going from `open` to `closed` stamps
 *   `disconnected_at` and notifies `whatsapp_account_disconnected`. A
 *   `close` while still pairing (an expired QR code, the restart after the
 *   scan) is not a disconnection, so it notifies nobody.
 * - `messages.upsert` (`data`: one message) and `messages.set` (`data`: the
 *   history handed over on linking, a list or `{messages: [...]}`): the
 *   one-to-one messages are kept (RecordWhatsappChatMessages); only the
 *   live ones count as unread and reach the owner's screens.
 * - `messages.update` (`data`: `{keyId, remoteJid, fromMe, status}`, or a
 *   list of them): a message the number sent takes its new receipt (the
 *   ticks; WhatsappChatMessage::raiseReceipt()) and its chat's screens are
 *   told.
 * - `messages.delete` (`data`: the key of a message deleted for everyone,
 *   `{id, remoteJid, fromMe, status: DELETED}`, or a list of them): the
 *   message is marked, and kept (RecordWhatsappChatMessages::revoke()).
 * - `presence.update` (`data`: `{id, presences: {participant JID:
 *   {lastKnownPresence, lastSeen?}}}`, as Baileys gives it): someone of a
 *   chat (known by its phone or `@lid` JID) is typing, recording, online
 *   or gone; the owner's screens are told (`WhatsappChatPresenceChanged`)
 *   and nothing is kept. WhatsApp sends them only for the chats the number
 *   subscribed to, which the chat screen does on opening one.
 *
 * Returns whether the event was applied: an unknown instance, event or
 * state is ignored. Repeating a state changes nothing.
 */
final readonly class HandleEvolutionWebhook
{
    public const string QR_CODE_EVENT = 'qrcode.updated';

    public const string CONNECTION_EVENT = 'connection.update';

    public const string MESSAGES_UPSERT_EVENT = 'messages.upsert';

    public const string MESSAGES_SET_EVENT = 'messages.set';

    public const string MESSAGES_UPDATE_EVENT = 'messages.update';

    public const string MESSAGES_DELETE_EVENT = 'messages.delete';

    public const string PRESENCE_EVENT = 'presence.update';

    public const string MESSAGES_EDITED_EVENT = 'messages.edited';

    public const string SEND_MESSAGE_EVENT = 'send.message';

    public const string SEND_MESSAGE_UPDATE_EVENT = 'send.message.update';

    public const string GROUPS_UPSERT_EVENT = 'groups.upsert';

    public const string GROUP_UPDATE_EVENT = 'group.update';

    public const string GROUP_PARTICIPANTS_EVENT = 'group.participants.update';

    /**
     * The presences WhatsApp reports.
     *
     * @var list<string>
     */
    public const array PRESENCES = ['composing', 'recording', 'paused', 'available', 'unavailable'];

    /**
     * The Evolution API states and the status each one means.
     *
     * @var array<string, string>
     */
    private const array STATES = [
        'connecting' => WhatsappConnectionStatus::CONNECTING,
        'open' => WhatsappConnectionStatus::OPEN,
        'close' => WhatsappConnectionStatus::CLOSED,
        'refused' => WhatsappConnectionStatus::CLOSED,
    ];

    public function __construct(
        private NotificationCenter $center,
        private RecordWhatsappChatMessages $recordMessages,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(array $payload): bool
    {
        $instance = $payload['instance'] ?? null;

        if (! is_string($instance) || $instance === '') {
            return false;
        }

        $account = WhatsappAccount::query()->where('instance_name', $instance)->first();

        if ($account === null) {
            return false;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return match (self::normalizeEvent($payload['event'] ?? null)) {
            self::QR_CODE_EVENT => $this->qrCodeUpdated($account, $data),
            self::CONNECTION_EVENT => $this->connectionUpdated($account, $data),
            self::MESSAGES_UPSERT_EVENT => $this->messagesReceived($account, $data, live: true),
            self::MESSAGES_SET_EVENT => $this->messagesReceived($account, $data, live: false),
            self::MESSAGES_UPDATE_EVENT => $this->receiptsUpdated($account, $data),
            self::MESSAGES_DELETE_EVENT => $this->messagesDeleted($account, $data),
            self::PRESENCE_EVENT => $this->presenceUpdated($account, $data),
            self::MESSAGES_EDITED_EVENT, self::SEND_MESSAGE_UPDATE_EVENT => $this->messagesEdited($account, $data),
            self::SEND_MESSAGE_EVENT => $this->messagesReceived($account, $data, live: true),
            self::GROUPS_UPSERT_EVENT, self::GROUP_UPDATE_EVENT => $this->groupsUpdated($account, $data),
            self::GROUP_PARTICIPANTS_EVENT => $this->groupParticipantsUpdated($account, $data),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function qrCodeUpdated(WhatsappAccount $account, array $data): bool
    {
        $qrCode = EvolutionQrCode::fromPayload(is_array($data['qrcode'] ?? null) ? $data['qrcode'] : null);

        if ($qrCode === null || $account->isOpen()) {
            return false;
        }

        WhatsappQrCodeUpdated::dispatch($account->user_id, $account->friendly_id, (string) $qrCode->base64, $qrCode->pairingCode);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function connectionUpdated(WhatsappAccount $account, array $data): bool
    {
        $status = self::STATES[$data['state'] ?? null] ?? null;

        if ($status === null) {
            return false;
        }

        $wasOpen = $account->isOpen();
        $statusId = WhatsappConnectionStatus::idFor($status);

        if ($account->whatsapp_connection_status_id === $statusId) {
            return true;
        }

        $attributes = ['whatsapp_connection_status_id' => $statusId];

        if ($status === WhatsappConnectionStatus::OPEN) {
            $attributes['connected_at'] = now();
            $attributes['disconnected_at'] = null;
            $attributes['phone_number'] = self::phoneNumberFrom($data['wuid'] ?? null) ?? $account->phone_number;
        }

        if ($status === WhatsappConnectionStatus::CLOSED && $wasOpen) {
            $attributes['disconnected_at'] = now();
        }

        $account->forceFill($attributes)->save();

        WhatsappAccountStatusChanged::dispatch($account->user_id, $account->friendly_id, $status);

        if ($status === WhatsappConnectionStatus::OPEN) {
            SyncWhatsappTargets::dispatch($account);
        }

        if ($status === WhatsappConnectionStatus::CLOSED && $wasOpen) {
            $this->center->dispatch(new WhatsappAccountDisconnected($account), $account->user);
        }

        return true;
    }

    /**
     * One message (an object with a `key`), a list of them, or a list under
     * `messages`.
     *
     * @param  array<string, mixed>  $data
     */
    private function messagesReceived(WhatsappAccount $account, array $data, bool $live): bool
    {
        $messages = match (true) {
            isset($data['key']) => [$data],
            is_array($data['messages'] ?? null) => $data['messages'],
            default => $data,
        };

        ($this->recordMessages)($account, array_values(array_filter($messages, is_array(...))), $live);

        return true;
    }

    /**
     * Messages edited: the `protocolMessage` of the edit (`messages.edited`),
     * or a message carrying it (`send.message.update`, an edit made through
     * the API); one, or a list. Any other `protocolMessage` (a revoke, a
     * timer…) also comes as `messages.edited`, and is left out.
     *
     * @param  array<string, mixed>  $data
     */
    private function messagesEdited(WhatsappAccount $account, array $data): bool
    {
        $items = array_filter(array_is_list($data) ? $data : [$data], is_array(...));
        $edits = [];

        foreach ($items as $item) {
            $protocol = $item['message']['protocolMessage'] ?? $item['protocolMessage'] ?? $item;
            $edit = is_array($protocol) ? $this->recordMessages->editOf($protocol) : null;

            if ($edit !== null) {
                $edits[] = $edit;
            }
        }

        $this->recordMessages->edit($account, $edits);

        return $edits !== [];
    }

    /**
     * Groups the number joined or whose info changed (`groups.upsert`,
     * `group.update`: Baileys' metadata, one or a list): the subject, the
     * description and the timer of disappearing messages are kept.
     *
     * @param  array<string, mixed>  $data
     */
    private function groupsUpdated(WhatsappAccount $account, array $data): bool
    {
        $changed = false;

        foreach (array_filter(array_is_list($data) ? $data : [$data], is_array(...)) as $group) {
            $jid = $group['id'] ?? null;
            $chat = is_string($jid) ? $account->targets()->where('jid', $jid)->first() : null;

            if ($chat === null) {
                continue;
            }

            $attributes = array_filter([
                'name' => is_string($group['subject'] ?? null) && mb_trim($group['subject']) !== '' ? mb_trim($group['subject']) : null,
                'description' => is_string($group['desc'] ?? null) ? $group['desc'] : null,
                'ephemeral_seconds' => is_numeric($group['ephemeralDuration'] ?? null) ? (int) $group['ephemeralDuration'] : null,
            ], fn (mixed $value): bool => $value !== null);

            $chat->forceFill($attributes);

            if ($chat->isDirty()) {
                $chat->save();
                WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);
                $changed = true;
            }

            Cache::forget($chat->participantsCacheKey());
        }

        return $changed;
    }

    /**
     * Someone was added to, removed from, promoted or demoted in a group:
     * its members are fetched again next time (WhatsappTarget::participantsCacheKey())
     * and its screens are told.
     *
     * @param  array<string, mixed>  $data
     */
    private function groupParticipantsUpdated(WhatsappAccount $account, array $data): bool
    {
        $jid = $data['id'] ?? null;
        $chat = is_string($jid) ? $account->targets()->where('jid', $jid)->first() : null;

        if ($chat === null) {
            return false;
        }

        Cache::forget($chat->participantsCacheKey());
        WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);

        return true;
    }

    /**
     * Messages deleted for everyone: one key, or a list.
     *
     * @param  array<string, mixed>  $data
     */
    private function messagesDeleted(WhatsappAccount $account, array $data): bool
    {
        $keys = array_filter(isset($data['id']) || isset($data['keyId']) ? [$data] : $data, is_array(...));
        $ids = array_values(array_filter(
            array_map(fn (array $key): mixed => $key['id'] ?? $key['keyId'] ?? null, $keys),
            fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        return $this->recordMessages->revoke($account, $ids) > 0;
    }

    /**
     * New receipts of messages the number sent: one update, or a list.
     *
     * @param  array<string, mixed>  $data
     */
    private function receiptsUpdated(WhatsappAccount $account, array $data): bool
    {
        $updates = array_filter(isset($data['keyId']) ? [$data] : $data, is_array(...));
        $changed = false;

        foreach ($updates as $update) {
            $receipt = WhatsappChatMessage::receiptFrom($update['status'] ?? null);
            $externalId = $update['keyId'] ?? null;

            if ($receipt === null || ! is_string($externalId) || $externalId === '') {
                continue;
            }

            $message = WhatsappChatMessage::query()
                ->whereIn('whatsapp_target_id', $account->targets()->select('id'))
                ->where('external_id', $externalId)
                ->with('target')
                ->first();

            if ($message === null) {
                continue;
            }

            if ($message->from_me) {
                $this->keepReceipt($message, $update, $receipt);
            }

            $updated = $message->from_me
                ? WhatsappChatMessage::raiseReceipt(WhatsappChatMessage::query()->whereKey($message->id), [$externalId], $receipt) > 0
                : $receipt >= WhatsappChatMessage::RECEIPT_READ && $this->readOnPhone($account, $message);

            if ($updated) {
                WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $message->target->friendly_id);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Keeps when a message the number sent reached the contact, and when
     * they read or played it, for its info (a time is set once). Only in a
     * one-to-one chat: in a group WhatsApp tells each member's receipt in
     * an event the Evolution API does not pass on.
     *
     * @param  array<string, mixed>  $update
     */
    private function keepReceipt(WhatsappChatMessage $message, array $update, int $receipt): void
    {
        if ($message->target->isGroup() || $receipt < WhatsappChatMessage::RECEIPT_DELIVERED) {
            return;
        }

        $row = WhatsappChatReceipt::query()->firstOrNew([
            'whatsapp_chat_message_id' => $message->id,
            'participant' => mb_substr((string) ($update['remoteJid'] ?? $message->target->jid), 0, 80),
        ]);

        $row->delivered_at ??= now();

        if ($receipt >= WhatsappChatMessage::RECEIPT_READ) {
            $row->read_at ??= now();
        }

        if ($receipt >= WhatsappChatMessage::RECEIPT_PLAYED) {
            $row->played_at ??= now();
        }

        $row->save();
    }

    /**
     * A message the contact sent was read on the phone (or WhatsApp Web):
     * WhatsApp needs no read receipt from Jarvis up to it (`read_up_to`),
     * and, when the number syncs what is read on the phone
     * (`syncs_phone_reads`), the chat is unread only after it.
     */
    private function readOnPhone(WhatsappAccount $account, WhatsappChatMessage $message): bool
    {
        $chat = $message->target;

        if ($chat->read_up_to === null || $chat->read_up_to->lessThan($message->sent_at)) {
            $chat->read_up_to = $message->sent_at;
        }

        if ($account->syncs_phone_reads ?? true) {
            $chat->unread_count = min($chat->unread_count, $chat->chatMessages()
                ->where('from_me', false)
                ->where('sent_at', '>', $message->sent_at)
                ->count());
        }

        $changed = $chat->isDirty('unread_count');
        $chat->save();

        return $changed;
    }

    /**
     * Who of a chat is typing, recording, online or gone.
     *
     * @param  array<string, mixed>  $data
     */
    private function presenceUpdated(WhatsappAccount $account, array $data): bool
    {
        $chatJid = $data['id'] ?? null;
        $presences = $data['presences'] ?? null;

        if (! is_string($chatJid) || $chatJid === '' || ! is_array($presences)) {
            return false;
        }

        $chat = $account->targets()
            ->where(fn ($query) => $query->where('jid', $chatJid)->orWhere('lid', $chatJid))
            ->first();

        if ($chat === null) {
            return false;
        }

        $told = false;

        foreach ($presences as $participant => $presence) {
            $state = is_array($presence) ? ($presence['lastKnownPresence'] ?? null) : null;

            if (! in_array($state, self::PRESENCES, true)) {
                continue;
            }

            $lastSeen = is_numeric($presence['lastSeen'] ?? null) && (int) $presence['lastSeen'] > 0
                ? Carbon::createFromTimestamp((int) $presence['lastSeen'])->toIso8601String()
                : null;

            WhatsappChatPresenceChanged::dispatch(
                $account->user_id,
                $account->friendly_id,
                $chat->friendly_id,
                $state,
                $chat->isGroup() ? $this->participantName($account, $chat, (string) $participant) : null,
                $lastSeen,
            );
            $told = true;
        }

        return $told;
    }

    /**
     * The name of a group participant: the account's contact with that JID,
     * else the push name of its latest message in the group, else its number
     * (none for an `@lid` JID, whose digits are no number).
     */
    private function participantName(WhatsappAccount $account, WhatsappTarget $group, string $jid): ?string
    {
        $contact = $account->targets()
            ->where(fn ($query) => $query->where('jid', $jid)->orWhere('lid', $jid))
            ->first();

        if ($contact !== null && ($contact->nickname !== null || $contact->name !== null)) {
            return $contact->displayName();
        }

        $pushName = $group->chatMessages()
            ->where('sender_jid', $jid)
            ->whereNotNull('sender_name')
            ->latest('sent_at')
            ->value('sender_name');

        return $pushName ?? (str_ends_with($jid, WhatsappTarget::CONTACT_JID_SUFFIX) ? self::phoneNumberFrom($jid) : null);
    }

    /**
     * `qrcode.updated` whatever the spelling: the global webhook sends
     * `QRCODE_UPDATED`-style names on some versions, and the participants
     * of a group come as `group-participants.update`.
     */
    private static function normalizeEvent(mixed $event): ?string
    {
        return is_string($event) ? str_replace(['_', '-'], '.', mb_strtolower($event)) : null;
    }

    /**
     * The E.164 number of a JID (`5511999990000@s.whatsapp.net` →
     * `+5511999990000`), or null when it carries none.
     */
    private static function phoneNumberFrom(mixed $jid): ?string
    {
        if (! is_string($jid)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', strtok($jid, '@:') ?: '');

        return $digits !== null && $digits !== '' && mb_strlen($digits) <= 15 ? '+'.$digits : null;
    }
}
