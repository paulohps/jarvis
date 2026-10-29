<?php

namespace App\Http\Controllers\Api\V1\Chats;

use App\Actions\Chats\DeleteChatTag;
use App\Actions\Chats\SaveChatTag;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatTagResource;
use App\Models\ChatTag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The user's own tags for the chat screen: listed by name, created,
 * renamed or recolored, and deleted (off every conversation).
 */
class ChatTagController extends Controller
{
    /**
     * `GET /api/v1/chats/tags`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ChatTagResource::collection($this->actor($request)->chatTags()->orderBy('name')->get());
    }

    /**
     * `POST /api/v1/chats/tags`: `{ name, color }`, the color by name.
     */
    public function store(Request $request, SaveChatTag $saveTag): JsonResponse
    {
        $tag = $saveTag($this->actor($request), null, $request->input('name'), $request->input('color'));

        return (new ChatTagResource($tag))->response()->setStatusCode(201);
    }

    /**
     * `PATCH /api/v1/chats/tags/{tag}`: `{ name, color }`.
     */
    public function update(Request $request, string $tag, SaveChatTag $saveTag): ChatTagResource
    {
        $found = $this->tagOf($this->actor($request), $tag);

        return new ChatTagResource($saveTag($this->actor($request), $found, $request->input('name', $found->name), $request->input('color', $found->color)));
    }

    /**
     * `DELETE /api/v1/chats/tags/{tag}`.
     */
    public function destroy(Request $request, string $tag, DeleteChatTag $deleteTag): Response
    {
        $deleteTag($this->actor($request), $this->tagOf($this->actor($request), $tag));

        return response()->noContent();
    }

    private function tagOf(User $user, string $friendlyId): ChatTag
    {
        return $user->chatTags()->byFriendlyId($friendlyId)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
