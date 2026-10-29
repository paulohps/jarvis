<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\ArchiveWhatsappChat;
use App\Actions\Whatsapp\BlockWhatsappContact;
use App\Actions\Whatsapp\CreateWhatsappChatReminder;
use App\Actions\Whatsapp\DraftWhatsappReply;
use App\Actions\Whatsapp\ForwardWhatsappChatMessages;
use App\Actions\Whatsapp\MarkWhatsappChatUnread;
use App\Actions\Whatsapp\MuteWhatsappChat;
use App\Actions\Whatsapp\PinWhatsappChat;
use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\SendWhatsappChatMessage;
use App\Actions\Whatsapp\SendWhatsappReaction;
use App\Actions\Whatsapp\SendWhatsappReadReceipts;
use App\Actions\Whatsapp\SetWhatsappChatNickname;
use App\Actions\Whatsapp\SetWhatsappChatNotifications;
use App\Actions\Whatsapp\SetWhatsappChatPriority;
use App\Actions\Whatsapp\SetWhatsappChatPrivacy;
use App\Actions\Whatsapp\SetWhatsappChatTags;
use App\Actions\Whatsapp\ShowWhatsappTyping;
use App\Actions\Whatsapp\StartWhatsappChat;
use App\Actions\Whatsapp\SyncWhatsappChat;
use App\Actions\Whatsapp\WatchWhatsappChatPresence;
use App\Exceptions\AiProviderNotConfigured;
use App\Exceptions\EvolutionApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappConversationMessageResource;
use App\Http\Resources\WhatsappConversationResource;
use App\Http\Resources\WhatsappDeliveryResource;
use App\Models\DeliveryStatus;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The WhatsApp screen of the apps, as on the web: the chats of the user's
 * numbers (contacts and groups), their messages, sending a text or a file,
 * marking a chat read and showing "typing…". What reaches WhatsApp besides
 * the messages (the read receipts, "typing…") follows the chat's settings.
 * The chat tools of the web too: its details (`show`) and settings
 * (`update`: pin, priority, nickname, tags, privacy, pushes), starting a
 * chat with a number, syncing it, an answer drafted by the AI, a reminder
 * and a scheduled message.
 */
class WhatsappConversationController extends Controller
{
    public const int CHATS_PER_PAGE = 30;

    public const int MAX_CHATS_PER_PAGE = 50;

    public const int MESSAGES_PER_PAGE = 40;

    /**
     * `GET /api/v1/whatsapp/conversations`: the chats with a message or
     * pinned, of all the user's accounts or one (`account`), the pinned
     * first, then the latest message first; `search` matches the name,
     * nickname or number, `tag` keeps the chats with that tag, and
     * `sort=priority` puts the highest priority first after the pinned ones.
     * The archived chats are left out, unless searching; `archived=1` lists
     * only them. Paginated (`per_page` up to 50).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->actor($request);
        $perPage = min(max($request->integer('per_page', self::CHATS_PER_PAGE), 1), self::MAX_CHATS_PER_PAGE);
        $search = mb_trim($request->string('search')->toString());
        $account = $request->string('account')->toString();
        $tag = $request->string('tag')->toString();

        return WhatsappConversationResource::collection(WhatsappTarget::query()
            ->whereHas('account', fn (Builder $query) => $query->where('user_id', $user->id)
                ->when($account !== '', fn (Builder $query) => $query->where('friendly_id', $account)))
            ->where(fn (Builder $query) => $query->whereNotNull('last_message_at')->orWhereNotNull('pinned_at'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('nickname', "%{$search}%")
                ->orWhereLike('jid', '%'.ltrim($search, '+').'%')))
            ->when($tag !== '', fn (Builder $query) => $query->whereHas('tags', fn (Builder $query) => $query->where('friendly_id', $tag)))
            ->when($request->boolean('archived'), fn (Builder $query) => $query->whereNotNull('archived_at'))
            ->when(! $request->boolean('archived') && $search === '', fn (Builder $query) => $query->whereNull('archived_at'))
            ->with(['account', 'lastChatMessage', 'tags'])
            ->orderByRaw('pinned_at is null')
            ->orderByDesc('pinned_at')
            ->when($request->string('sort')->toString() === 'priority', fn (Builder $query) => $query->orderByRaw('priority is null')->orderBy('priority'))
            ->orderByRaw('last_message_at is null')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage));
    }

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}/messages`: MESSAGES_PER_PAGE
     * messages, newest first, older than `before` (a message's friendly id)
     * when given, with `meta.has_more`. The first page also carries the
     * texts sent from Jarvis still waiting to go out or failed in the last
     * day (`outgoing`). Asks WhatsApp for the chat's presence
     * (WatchWhatsappChatPresence), broadcast as `whatsapp.chat.presence`.
     */
    public function messages(Request $request, WhatsappTarget $chat, WatchWhatsappChatPresence $watchPresence): JsonResponse
    {
        Gate::authorize('view', $chat->account);

        if (! $request->filled('before')) {
            $watchPresence($this->actor($request), $chat);
        }

        $before = $request->filled('before')
            ? $chat->chatMessages()->where('friendly_id', $request->string('before')->toString())->first()
            : null;

        $messages = $chat->chatMessages()
            ->when($before !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('sent_at', '<', $before->sent_at)
                ->orWhere(fn (Builder $query) => $query->where('sent_at', $before->sent_at)->where('id', '<', $before->id))))
            ->with(['reactions', 'pollVotes'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();
        WhatsappChatMessage::withContext($messages, $chat);

        $outgoing = $request->filled('before') ? [] : WhatsappDeliveryResource::collection(WhatsappDelivery::query()
            ->with(['status', 'source', 'account', 'target', 'message'])
            ->where('whatsapp_target_id', $chat->id)
            ->where('whatsapp_delivery_source_id', WhatsappDeliverySource::idFor(WhatsappDeliverySource::CHAT))
            ->whereIn('delivery_status_id', [DeliveryStatus::idFor(DeliveryStatus::PENDING), DeliveryStatus::idFor(DeliveryStatus::FAILED)])
            ->where('created_at', '>=', now()->subDay())
            ->oldest()
            ->get())->resolve($request);

        return response()->json([
            'data' => WhatsappConversationMessageResource::collection($messages->take(self::MESSAGES_PER_PAGE))->resolve($request),
            'outgoing' => $outgoing,
            'meta' => ['has_more' => $messages->count() > self::MESSAGES_PER_PAGE],
        ]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/messages`: `{ text, file?,
     * reply_to? }` (multipart for a file, the text its caption; `reply_to`
     * the friendly id of a message of the chat it answers), sent from the
     * chat's number now (SendWhatsappChatMessage; in a group, `@number`
     * mentions that member). Answers 202 with the delivery; the message
     * itself shows in the chat once WhatsApp reports it.
     *
     * @throws ValidationException
     */
    public function send(Request $request, WhatsappTarget $chat, SendWhatsappChatMessage $sendMessage): JsonResponse
    {
        $replyTo = $request->filled('reply_to') ? $chat->chatMessages()->where('friendly_id', $request->string('reply_to')->toString())->first() : null;

        if ($request->filled('reply_to') && $replyTo === null) {
            throw ValidationException::withMessages(['reply_to' => __('The message answered is not of this chat.')]);
        }

        $delivery = $sendMessage($this->actor($request), $chat, $request->string('text')->toString(), $request->file('file'), $replyTo);

        return (new WhatsappDeliveryResource($delivery->load(['status', 'source', 'account', 'target', 'message'])))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/read`: the user saw the
     * chat. Its unread count goes back to 0 and, when the chat sends read
     * receipts, WhatsApp is told too (SendWhatsappReadReceipts).
     */
    public function read(Request $request, WhatsappTarget $chat, SendWhatsappReadReceipts $sendReceipts): Response
    {
        Gate::authorize('use', $chat->account);

        if ($chat->unread_count > 0 || $chat->marked_unread) {
            $chat->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();
        }

        $sendReceipts($this->actor($request), $chat);

        return response()->noContent();
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/typing`: the user is
     * typing; when the chat sends "typing…", the contact sees it for a few
     * seconds (ShowWhatsappTyping). Answers `{ shown }`.
     */
    public function typing(Request $request, WhatsappTarget $chat, ShowWhatsappTyping $showTyping): JsonResponse
    {
        return response()->json(['shown' => $showTyping($this->actor($request), $chat)]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations`: `{ account, number }`, the chat
     * with a number on one of the user's numbers, started when there is
     * none yet (StartWhatsappChat). Answers 201 with the chat.
     *
     * @throws ValidationException
     */
    public function start(Request $request, StartWhatsappChat $startChat): JsonResponse
    {
        $account = WhatsappAccount::query()->where('friendly_id', $request->string('account')->toString())->first();

        if ($account === null) {
            throw ValidationException::withMessages(['account' => __('Pick one of your numbers.')]);
        }

        $chat = $startChat($this->actor($request), $account, $request->string('number')->toString());

        return (new WhatsappConversationResource($chat->load(['account', 'lastChatMessage', 'tags'])))->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}`: the chat, plus its
     * messages scheduled and not sent yet (the next first) and its open
     * reminders (the next first).
     */
    public function show(Request $request, WhatsappTarget $chat): JsonResponse
    {
        Gate::authorize('view', $chat->account);

        $user = $this->actor($request);
        $chat->load(['account', 'lastChatMessage', 'tags']);

        $scheduled = WhatsappMessage::query()
            ->where('user_id', $user->id)
            ->whereNull('cancelled_at')
            ->whereHas('targets', fn (Builder $query) => $query->whereKey($chat->id))
            ->whereHas('recurrence', fn (Builder $query) => $query->whereNotNull('next_run_at')->where('is_paused', false))
            ->with('recurrence')
            ->get()
            ->sortBy(fn (WhatsappMessage $message) => $message->recurrence?->next_run_at)
            ->values();

        $reminders = $chat->reminders()
            ->visibleTo($user)
            ->whereHas('occurrences', fn (Builder $query) => $query->whereNull('completed_at'))
            ->with(['occurrences' => fn (HasMany $query) => $query->whereNull('completed_at')->orderBy('due_at')])
            ->get()
            ->sortBy(fn (Reminder $reminder) => $reminder->occurrences->first()?->due_at)
            ->values();

        return response()->json([
            'data' => (new WhatsappConversationResource($chat))->resolve($request),
            'scheduled' => $scheduled->map($this->scheduledItem(...))->all(),
            'reminders' => $reminders->map(fn (Reminder $reminder): array => [
                'friendly_id' => $reminder->friendly_id,
                'title' => $reminder->title,
                'due_at' => $reminder->occurrences->first()?->due_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * `PATCH /api/v1/whatsapp/conversations/{chat}`: any of `{ pinned,
     * priority (1–5 or null), nickname (null drops it), tags (friendly ids
     * of the user's tags), sends_read_receipts, sends_typing,
     * notifies_messages (each true, false or null to follow the number),
     * archived, marked_unread, muted (`8h`, `1w`, `always`, or null to
     * unmute), blocked (a contact; needs the number connected) }`; the ones
     * left out stay as they are. Answers the chat; a failing WhatsApp is a
     * 502.
     *
     * @throws ValidationException
     */
    public function update(
        Request $request,
        WhatsappTarget $chat,
        PinWhatsappChat $pinChat,
        SetWhatsappChatPriority $setPriority,
        SetWhatsappChatNickname $setNickname,
        SetWhatsappChatTags $setTags,
        SetWhatsappChatPrivacy $setPrivacy,
        SetWhatsappChatNotifications $setNotifications,
        ArchiveWhatsappChat $archiveChat,
        MarkWhatsappChatUnread $markUnread,
        MuteWhatsappChat $muteChat,
        BlockWhatsappContact $blockContact,
    ): WhatsappConversationResource {
        Gate::authorize('use', $chat->account);

        $input = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'nullable', 'integer'],
            'nickname' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string'],
            'sends_read_receipts' => ['sometimes', 'nullable', 'boolean'],
            'sends_typing' => ['sometimes', 'nullable', 'boolean'],
            'notifies_messages' => ['sometimes', 'nullable', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'marked_unread' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'nullable', 'string'],
            'blocked' => ['sometimes', 'boolean'],
        ]);
        $user = $this->actor($request);

        if (array_key_exists('pinned', $input)) {
            $pinChat($user, $chat, (bool) $input['pinned']);
        }

        if (array_key_exists('priority', $input)) {
            $setPriority($user, $chat, $input['priority'] === null ? null : (int) $input['priority']);
        }

        if (array_key_exists('nickname', $input)) {
            $setNickname($user, $chat, $input['nickname']);
        }

        if (array_key_exists('tags', $input)) {
            $setTags($user, $chat, array_values($input['tags']));
        }

        if (array_key_exists('sends_read_receipts', $input) || array_key_exists('sends_typing', $input)) {
            $setPrivacy->forChat(
                $user,
                $chat,
                array_key_exists('sends_read_receipts', $input) ? $input['sends_read_receipts'] : $chat->sends_read_receipts,
                array_key_exists('sends_typing', $input) ? $input['sends_typing'] : $chat->sends_typing,
            );
        }

        if (array_key_exists('notifies_messages', $input)) {
            $setNotifications->forChat($user, $chat, $input['notifies_messages']);
        }

        if (array_key_exists('muted', $input)) {
            $muteChat($user, $chat, $input['muted']);
        }

        try {
            if (array_key_exists('archived', $input)) {
                $archiveChat($user, $chat, (bool) $input['archived']);
            }

            if (array_key_exists('marked_unread', $input)) {
                $markUnread($user, $chat, (bool) $input['marked_unread']);
            }

            if (array_key_exists('blocked', $input)) {
                $blockContact($user, $chat, (bool) $input['blocked']);
            }
        } catch (EvolutionApiException $exception) {
            report($exception);

            throw new HttpException(502, __('WhatsApp did not take it. Try again in a moment.'), $exception);
        }

        return new WhatsappConversationResource($chat->refresh()->load(['account', 'lastChatMessage', 'tags']));
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/sync`: fetches the chat's
     * messages again from WhatsApp (SyncWhatsappChat). Answers `{ added }`,
     * how many were new; 409 while the number is not connected, 502 when
     * WhatsApp cannot be reached.
     */
    public function sync(Request $request, WhatsappTarget $chat, SyncWhatsappChat $syncChat): JsonResponse
    {
        try {
            $added = $syncChat($this->actor($request), $chat);
        } catch (EvolutionApiException $exception) {
            report($exception);

            throw new HttpException(502, __('WhatsApp could not be reached. Try again in a moment.'), $exception);
        }

        abort_if($added === null, 409, __(':nickname is not connected, so there is nothing to sync.', ['nickname' => $chat->account->nickname]));

        return response()->json(['added' => $added]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/draft`: `{ messages?,
     * instruction? }`, an answer drafted by the AI in the user's style to
     * the picked messages (friendly ids), or to what the contact sent since
     * the user's last message (WhatsappTarget::latestReceivedChatMessages),
     * following the `instruction` when there is one. With `without_context`,
     * a new message from the `instruction` alone, without reading the chat
     * (DraftWhatsappReply::fromPrompt()). Answers `{ text }` for the composer; nothing is sent. No active
     * AI connection is a 503, a provider failing a 502.
     *
     * @throws AiProviderNotConfigured
     * @throws ValidationException
     */
    public function draft(Request $request, WhatsappTarget $chat, DraftWhatsappReply $draftReply): JsonResponse
    {
        Gate::authorize('use', $chat->account);

        $withoutContext = $request->boolean('without_context');
        $picked = array_filter((array) $request->input('messages', []), is_string(...));
        $messages = $withoutContext ? [] : ($picked !== []
            ? $chat->chatMessages()->whereIn('friendly_id', $picked)->orderBy('sent_at')->get()->all()
            : $chat->latestReceivedChatMessages());

        if (! $withoutContext && $messages === []) {
            throw ValidationException::withMessages(['messages' => __('The contact has not sent anything to answer yet.')]);
        }

        try {
            $text = $withoutContext
                ? $draftReply->fromPrompt($this->actor($request), $chat, $request->string('instruction')->toString())
                : $draftReply($this->actor($request), array_values($messages), $request->string('instruction')->toString() ?: null);
        } catch (ValidationException|AiProviderNotConfigured $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new HttpException(502, __('The assistant could not write a reply right now. Try again in a moment.'), $exception);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/reminders`: `{ title,
     * due_at, messages?: [friendly_id] }` (or a single `message`), a
     * reminder about the chat, or some of its messages (all quoted in the
     * note), as the reminders API reads `due_at`
     * (CreateWhatsappChatReminder; the `reminders` module is needed too).
     * Answers 201 with `{ friendly_id, title, due_at }`.
     *
     * @throws ValidationException
     */
    public function remind(Request $request, WhatsappTarget $chat, CreateWhatsappChatReminder $createReminder): JsonResponse
    {
        $request->validate([
            'messages' => ['nullable', 'array', 'max:'.CreateWhatsappChatReminder::MAX_MESSAGES],
            'messages.*' => ['string'],
            'message' => ['nullable', 'string'],
        ]);

        $ids = array_values(array_unique([...$request->array('messages'), ...array_filter([$request->input('message')])]));
        $messages = $chat->chatMessages()->whereIn('friendly_id', $ids)->get()->all();

        if (count($messages) !== count($ids)) {
            throw ValidationException::withMessages(['messages' => __('Pick a message of this chat.')]);
        }

        $reminder = $createReminder($this->actor($request), $chat, $messages, [
            'title' => $request->input('title'),
            'due_at' => $request->input('due_at'),
        ]);
        $reminder->load(['occurrences' => fn (HasMany $query) => $query->whereNull('completed_at')->orderBy('due_at')]);

        return response()->json(['data' => [
            'friendly_id' => $reminder->friendly_id,
            'title' => $reminder->title,
            'due_at' => $reminder->occurrences->first()?->due_at?->toIso8601String(),
        ]], 201);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/messages/{message}/reaction`:
     * `{ emoji }`, the owner's reaction to a message of the chat; the same
     * emoji again, or '', removes it (SendWhatsappReaction). Answers
     * `{ emoji }`, null for none; a failing WhatsApp is a 502.
     *
     * @throws ValidationException
     */
    public function react(Request $request, WhatsappTarget $chat, string $message, SendWhatsappReaction $sendReaction): JsonResponse
    {
        $chatMessage = $chat->chatMessages()->where('friendly_id', $message)->firstOrFail();

        try {
            $emoji = $sendReaction($this->actor($request), $chatMessage, (string) $request->input('emoji', ''));
        } catch (EvolutionApiException $exception) {
            report($exception);

            throw new HttpException(502, __('WhatsApp did not take the reaction. Try again in a moment.'), $exception);
        }

        return response()->json(['emoji' => $emoji]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/forward`: `{ messages:
     * [friendly_id], to: [chat friendly_id] }`, the messages of the chat sent
     * again to other chats of its number (ForwardWhatsappChatMessages).
     * Answers 202 with `{ deliveries }`, how many sendings were queued.
     *
     * @throws ValidationException
     */
    public function forward(Request $request, WhatsappTarget $chat, ForwardWhatsappChatMessages $forward): JsonResponse
    {
        $request->validate([
            'messages' => ['required', 'array', 'max:'.ForwardWhatsappChatMessages::MAX_MESSAGES],
            'messages.*' => ['string'],
            'to' => ['required', 'array', 'max:'.ForwardWhatsappChatMessages::MAX_CHATS],
            'to.*' => ['string'],
        ]);

        $ids = array_values(array_unique($request->array('messages')));
        $messages = $chat->chatMessages()->whereIn('friendly_id', $ids)->get()->all();
        $chatIds = array_values(array_unique($request->array('to')));
        $chats = WhatsappTarget::query()->where('whatsapp_account_id', $chat->whatsapp_account_id)->whereIn('friendly_id', $chatIds)->get()
            ->sortBy(fn (WhatsappTarget $target): int|false => array_search($target->friendly_id, $chatIds, true))->values()->all();

        if (count($messages) !== count($ids)) {
            throw ValidationException::withMessages(['messages' => __('Pick messages of the same chat.')]);
        }

        if (count($chats) !== count($chatIds)) {
            throw ValidationException::withMessages(['to' => __('Forward to chats of the same number.')]);
        }

        try {
            $deliveries = $forward($this->actor($request), $messages, $chats);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            throw ValidationException::withMessages(array_filter([
                'messages' => $errors['messages'] ?? [],
                'to' => $errors['chats'] ?? [],
            ]));
        }

        return response()->json(['deliveries' => $deliveries->count()], 202);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/schedule`: `{ text, at,
     * file? }` (multipart with a file), the text sent to the chat from its
     * number once, at `at` (as the scheduled messages read `starts_at`); a
     * file goes with the text as its caption (the text is then optional; an
     * audio goes as a voice note, without text). Answers 201 with
     * `{ friendly_id, body, next_run_at, media }`; cancel it with
     * `POST /whatsapp/messages/{message}/cancel`.
     *
     * @throws ValidationException
     */
    public function schedule(Request $request, WhatsappTarget $chat, ScheduleWhatsappMessage $scheduleMessage): JsonResponse
    {
        Gate::authorize('use', $chat->account);

        try {
            $message = $scheduleMessage($this->actor($request), [
                'account' => $chat->account->friendly_id,
                'targets' => [$chat->friendly_id],
                'body' => $request->input('text'),
                'file' => $request->file('file'),
                'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => $request->input('at')],
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            throw ValidationException::withMessages(array_filter([
                'at' => [...($errors['recurrence.starts_at'] ?? []), ...($errors['recurrence'] ?? []), ...($errors['account'] ?? [])],
                'text' => $errors['body'] ?? [],
                'file' => $errors['file'] ?? [],
            ]));
        }

        return response()->json(['data' => $this->scheduledItem($message->load('recurrence'))], 201);
    }

    /**
     * A message scheduled to the chat, as the apps list it.
     *
     * @return array{friendly_id: string, body: string, next_run_at: string|null, media: array{mime: string|null, name: string|null, size: int|null}|null}
     */
    private function scheduledItem(WhatsappMessage $message): array
    {
        return [
            'friendly_id' => $message->friendly_id,
            'body' => $message->body,
            'next_run_at' => $message->recurrence?->next_run_at?->toIso8601String(),
            'media' => $message->media_path !== null
                ? ['mime' => $message->media_mime, 'name' => $message->media_name, 'size' => $message->media_size]
                : null,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
