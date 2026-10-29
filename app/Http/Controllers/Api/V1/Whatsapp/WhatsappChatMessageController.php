<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\DeleteWhatsappChatMessageForEveryone;
use App\Actions\Whatsapp\EditWhatsappChatMessage;
use App\Actions\Whatsapp\SendWhatsappChatContent;
use App\Actions\Whatsapp\StarWhatsappChatMessage;
use App\Exceptions\EvolutionApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappConversationMessageResource;
use App\Http\Resources\WhatsappDeliveryResource;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What the WhatsApp screen of the apps does with one message of a chat,
 * and finds in it: search, the starred messages, the media gallery,
 * starring, editing and deleting a message for everyone, its info (when
 * it reached the contact and was read), and sending a poll, a location,
 * contact cards or a sticker. A failing WhatsApp is a 502.
 */
class WhatsappChatMessageController extends Controller
{
    public const int RESULTS_PER_PAGE = 50;

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}/search?q=`: the messages
     * with every word searched for (at least two letters), newest first,
     * up to RESULTS_PER_PAGE.
     */
    public function search(Request $request, WhatsappTarget $chat): JsonResponse
    {
        Gate::authorize('view', $chat->account);
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->listed($request, $chat, $chat->searchMessages($request->string('q')->toString())->limit(self::RESULTS_PER_PAGE)->get());
    }

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}/starred`: the messages the
     * owner starred, latest starred first.
     */
    public function starred(Request $request, WhatsappTarget $chat): JsonResponse
    {
        Gate::authorize('view', $chat->account);

        return $this->listed($request, $chat, $chat->chatMessages()->whereNotNull('starred_at')->latest('starred_at')->limit(self::RESULTS_PER_PAGE)->get());
    }

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}/gallery?kind=&before=`: the
     * photos and videos (`media`, the default), documents (`documents`) or
     * messages with a link (`links`), newest first, a page at a time
     * (`before`: the friendly id of the last one shown).
     */
    public function gallery(Request $request, WhatsappTarget $chat): JsonResponse
    {
        Gate::authorize('view', $chat->account);
        $request->validate(['kind' => ['nullable', Rule::in(WhatsappTarget::GALLERY_KINDS)], 'before' => ['nullable', 'string']]);

        $before = $request->filled('before') ? $chat->chatMessages()->where('friendly_id', $request->string('before')->toString())->first() : null;
        $messages = $chat->galleryMessages($request->string('kind', WhatsappTarget::GALLERY_MEDIA)->toString())
            ->when($before !== null, fn ($query) => $query->where(fn ($query) => $query
                ->where('sent_at', '<', $before->sent_at)
                ->orWhere(fn ($query) => $query->where('sent_at', $before->sent_at)->where('id', '<', $before->id))))
            ->limit(self::RESULTS_PER_PAGE + 1)
            ->get();

        return $this->listed($request, $chat, $messages->take(self::RESULTS_PER_PAGE), ['has_more' => $messages->count() > self::RESULTS_PER_PAGE]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/messages/{message}/star`:
     * `{ starred }`. Kept in Jarvis only.
     */
    public function star(Request $request, WhatsappTarget $chat, string $message, StarWhatsappChatMessage $star): JsonResponse
    {
        $request->validate(['starred' => ['required', 'boolean']]);

        $chatMessage = $star($this->actor($request), $this->message($chat, $message), $request->boolean('starred'));

        return $this->shown($request, $chat, $chatMessage);
    }

    /**
     * `PATCH /api/v1/whatsapp/conversations/{chat}/messages/{message}`:
     * `{ text }`, the new text of a message the number sent, within
     * WhatsApp's 15 minutes (EditWhatsappChatMessage). Answers the message.
     *
     * @throws ValidationException
     */
    public function update(Request $request, WhatsappTarget $chat, string $message, EditWhatsappChatMessage $edit): JsonResponse
    {
        $chatMessage = $this->message($chat, $message);

        $this->onWhatsapp(fn () => $edit($this->actor($request), $chatMessage, $request->string('text')->toString()), __('WhatsApp did not take the edit. Try again in a moment.'));

        return $this->shown($request, $chat, $chatMessage);
    }

    /**
     * `DELETE /api/v1/whatsapp/conversations/{chat}/messages/{message}`:
     * deletes for everyone a message the number sent
     * (DeleteWhatsappChatMessageForEveryone); Jarvis keeps it, marked.
     * Answers the message.
     *
     * @throws ValidationException
     */
    public function destroy(Request $request, WhatsappTarget $chat, string $message, DeleteWhatsappChatMessageForEveryone $delete): JsonResponse
    {
        $chatMessage = $this->message($chat, $message);

        $this->onWhatsapp(fn () => $delete($this->actor($request), $chatMessage), __('WhatsApp did not delete it. Try again in a moment.'));

        return $this->shown($request, $chat, $chatMessage);
    }

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}/messages/{message}/info`:
     * for a message the number sent, how far it got (`receipt`) and when it
     * reached the contact and they read or played it (one-to-one chats
     * only: in a group WhatsApp does not tell Jarvis).
     */
    public function info(Request $request, WhatsappTarget $chat, string $message): JsonResponse
    {
        $chatMessage = $this->message($chat, $message)->load('receipts');
        $receipt = $chatMessage->receipts->first();

        return response()->json(['data' => [
            'friendly_id' => $chatMessage->friendly_id,
            'sent_at' => $chatMessage->sent_at->toIso8601String(),
            'receipt' => (new WhatsappConversationMessageResource($chatMessage))->resolve($request)['receipt'],
            'delivered_at' => $receipt?->delivered_at?->toIso8601String(),
            'read_at' => $receipt?->read_at?->toIso8601String(),
            'played_at' => $receipt?->played_at?->toIso8601String(),
            'per_member' => ! $chat->isGroup(),
        ]]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/poll`: `{ question,
     * options: [2–10], multiple?, reply_to? }`. Answers 202 with the delivery.
     *
     * @throws ValidationException
     */
    public function poll(Request $request, WhatsappTarget $chat, SendWhatsappChatContent $send): JsonResponse
    {
        $request->validate(['options' => ['required', 'array'], 'multiple' => ['sometimes', 'boolean']]);

        return $this->delivered($send->poll(
            $this->actor($request),
            $chat,
            $request->string('question')->toString(),
            array_values(array_map(strval(...), (array) $request->input('options'))),
            $request->boolean('multiple'),
            $this->replyTo($request, $chat),
        ));
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/location`: `{ latitude,
     * longitude, name?, address?, reply_to? }`. Answers 202 with the delivery.
     *
     * @throws ValidationException
     */
    public function location(Request $request, WhatsappTarget $chat, SendWhatsappChatContent $send): JsonResponse
    {
        $request->validate(['latitude' => ['required', 'numeric'], 'longitude' => ['required', 'numeric']]);

        return $this->delivered($send->location(
            $this->actor($request),
            $chat,
            (float) $request->input('latitude'),
            (float) $request->input('longitude'),
            $request->string('name')->toString(),
            $request->string('address')->toString(),
            $this->replyTo($request, $chat),
        ));
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/contacts`: `{ contacts:
     * [friendly id of a contact chat of the same number] }`. Answers 202
     * with the delivery.
     *
     * @throws ValidationException
     */
    public function contacts(Request $request, WhatsappTarget $chat, SendWhatsappChatContent $send): JsonResponse
    {
        $request->validate(['contacts' => ['required', 'array']]);

        return $this->delivered($send->contacts($this->actor($request), $chat, array_values(array_map(strval(...), (array) $request->input('contacts')))));
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/sticker`: multipart `{
     * file (WebP, PNG or JPEG up to 1 MB), reply_to? }`. Answers 202 with
     * the delivery.
     *
     * @throws ValidationException
     */
    public function sticker(Request $request, WhatsappTarget $chat, SendWhatsappChatContent $send): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        return $this->delivered($send->sticker($this->actor($request), $chat, $file, $this->replyTo($request, $chat)));
    }

    /**
     * The message of the chat `reply_to` names, if any.
     *
     * @throws ValidationException when it is not of the chat
     */
    private function replyTo(Request $request, WhatsappTarget $chat): ?WhatsappChatMessage
    {
        if (! $request->filled('reply_to')) {
            return null;
        }

        return $chat->chatMessages()->where('friendly_id', $request->string('reply_to')->toString())->first()
            ?? throw ValidationException::withMessages(['reply_to' => __('The message answered is not of this chat.')]);
    }

    private function delivered(WhatsappDelivery $delivery): JsonResponse
    {
        return (new WhatsappDeliveryResource($delivery->load(['status', 'source', 'account', 'target', 'message'])))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Runs what goes to WhatsApp; a failing WhatsApp is a 502.
     */
    private function onWhatsapp(callable $change, string $failure): void
    {
        try {
            $change();
        } catch (EvolutionApiException $exception) {
            report($exception);

            throw new HttpException(502, $failure, $exception);
        }
    }

    /**
     * @param  Collection<int, WhatsappChatMessage>  $messages
     * @param  array<string, mixed>  $meta
     */
    private function listed(Request $request, WhatsappTarget $chat, Collection $messages, array $meta = []): JsonResponse
    {
        $messages->load(['reactions', 'pollVotes']);
        WhatsappChatMessage::withContext($messages, $chat);

        return response()->json(array_filter([
            'data' => WhatsappConversationMessageResource::collection($messages)->resolve($request),
            'meta' => $meta === [] ? null : $meta,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function shown(Request $request, WhatsappTarget $chat, WhatsappChatMessage $message): JsonResponse
    {
        $message->load(['reactions', 'pollVotes']);
        WhatsappChatMessage::withContext(new Collection([$message]), $chat);

        return response()->json(['data' => (new WhatsappConversationMessageResource($message))->resolve($request)]);
    }

    private function message(WhatsappTarget $chat, string $friendlyId): WhatsappChatMessage
    {
        Gate::authorize('view', $chat->account);

        return $chat->chatMessages()->where('friendly_id', $friendlyId)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
