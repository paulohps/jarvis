<?php

namespace App\Http\Controllers\Api\V1\Chats;

use App\Actions\Chats\RenameChatContact;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatContactResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The user's contacts of the chat screen (the people behind conversations
 * on several channels): listed by name with their conversations, renamed
 * and annotated. A contact is made by linking a conversation
 * (ChatConversationController::link) and goes when no conversation is
 * left under it.
 */
class ChatContactController extends Controller
{
    /**
     * `GET /api/v1/chats/contacts`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ChatContactResource::collection($this->actor($request)->chatContacts()->with('conversations')->orderBy('name')->get());
    }

    /**
     * `PATCH /api/v1/chats/contacts/{contact}`: `{ name, notes? }`.
     */
    public function update(Request $request, string $contact, RenameChatContact $rename): ChatContactResource
    {
        $user = $this->actor($request);
        $found = $user->chatContacts()->byFriendlyId($contact)->firstOrFail();

        return new ChatContactResource($rename($user, $found, $request->input('name', $found->name), $request->input('notes', $found->notes))->load('conversations'));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
