<?php

namespace App\Http\Controllers\Api\V1\Chats;

use App\Actions\Chats\DeleteChatMessage;
use App\Actions\Chats\EditChatMessage;
use App\Actions\Chats\FindsChatConversations;
use App\Actions\Chats\ReactToChatMessage;
use App\Actions\Chats\SendChatMessage;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use App\Services\Chats\OutgoingChatFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The messages of a conversation of the chat screen, through its
 * channel's driver: the latest ones or the ones before a message, a
 * thread, sending (a text and/or files), reacting, editing and deleting.
 * Every answer is the `ChatMessage` shape (ChatMessageData::toArray()).
 */
class ChatMessageController extends Controller
{
    use FindsChatConversations;

    public const int PER_PAGE = 50;

    /**
     * `GET /api/v1/chats/conversations/{conversation}/messages`: the latest
     * `limit` (50 at most), or the ones before `before` (a message id),
     * oldest first; `meta.has_older` says whether there are more.
     */
    public function index(Request $request, string $conversation, ChatProviderRegistry $registry): JsonResponse
    {
        $found = $this->conversationOf($this->actor($request), $conversation);
        $driver = $registry->for($found);
        $limit = min(self::PER_PAGE, max(1, (int) $request->query('limit', (string) self::PER_PAGE)));

        try {
            $messages = $driver->messages($found, $request->query('before'), $limit);
            $oldest = $messages->first();

            return response()->json([
                'data' => $messages->map(fn (ChatMessageData $message): array => $message->toArray())->values()->all(),
                'meta' => ['has_older' => $oldest !== null && $driver->hasOlderMessages($found, $oldest->id)],
            ]);
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['before' => $exception->getMessage()]);
        }
    }

    /**
     * `GET /api/v1/chats/conversations/{conversation}/messages/{message}/thread`.
     */
    public function thread(Request $request, string $conversation, string $message, ChatProviderRegistry $registry): JsonResponse
    {
        $found = $this->conversationOf($this->actor($request), $conversation);

        try {
            $messages = $registry->for($found)->thread($found, $message);
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['message' => $exception->getMessage()]);
        }

        return response()->json(['data' => $messages->map(fn (ChatMessageData $item): array => $item->toArray())->values()->all()]);
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/messages`:
     * `{ text?, thread_id? }` with `files[]` (multipart).
     */
    public function store(Request $request, string $conversation, SendChatMessage $send): JsonResponse
    {
        $user = $this->actor($request);
        $found = $this->conversationOf($user, $conversation);

        $files = array_map(fn (UploadedFile $file): OutgoingChatFile => new OutgoingChatFile(
            (string) $file->getRealPath(),
            $file->getClientOriginalName(),
            $file->getMimeType(),
        ), array_values(array_filter((array) $request->file('files', []), fn (mixed $file): bool => $file instanceof UploadedFile)));

        try {
            $sent = $send($user, $found, $request->input('text'), $files, $request->input('thread_id'));
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['text' => $exception->getMessage()]);
        }

        return response()->json(['data' => $sent->toArray()], 201);
    }

    /**
     * `PATCH /api/v1/chats/conversations/{conversation}/messages/{message}`: `{ text }`.
     */
    public function update(Request $request, string $conversation, string $message, EditChatMessage $edit): JsonResponse
    {
        $user = $this->actor($request);

        try {
            $edited = $edit($user, $this->conversationOf($user, $conversation), $message, $request->input('text'));
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['text' => $exception->getMessage()]);
        }

        return response()->json(['data' => $edited->toArray()]);
    }

    /**
     * `DELETE /api/v1/chats/conversations/{conversation}/messages/{message}`.
     */
    public function destroy(Request $request, string $conversation, string $message, DeleteChatMessage $delete): Response
    {
        $user = $this->actor($request);

        try {
            $delete($user, $this->conversationOf($user, $conversation), $message);
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['message' => $exception->getMessage()]);
        }

        return response()->noContent();
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/messages/{message}/reaction`:
     * `{ emoji, on? }` (`on` false takes it back).
     */
    public function react(Request $request, string $conversation, string $message, ReactToChatMessage $reactTo, ChatProviderRegistry $registry): JsonResponse
    {
        $user = $this->actor($request);
        $found = $this->conversationOf($user, $conversation);

        try {
            $reactTo($user, $found, $message, (string) $request->input('emoji', ''), $request->boolean('on', true));
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['emoji' => $exception->getMessage()]);
        }

        return response()->json(['data' => $registry->for($found)->message($found, $message)?->toArray()]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
