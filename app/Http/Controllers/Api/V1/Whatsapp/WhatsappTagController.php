<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\DeleteWhatsappTag;
use App\Actions\Whatsapp\SaveWhatsappTag;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappTagResource;
use App\Models\User;
use App\Models\WhatsappTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The user's own tags for WhatsApp chats, as on the web: listed by name,
 * created, renamed or recolored, and deleted (off every chat). A name is
 * unique per user, whatever the case.
 */
class WhatsappTagController extends Controller
{
    /**
     * `GET /api/v1/whatsapp/tags`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return WhatsappTagResource::collection($this->actor($request)->whatsappTags()->orderBy('name')->get());
    }

    /**
     * `POST /api/v1/whatsapp/tags`: `{ name, color }`, the color by name.
     *
     * @throws ValidationException
     */
    public function store(Request $request, SaveWhatsappTag $saveTag): JsonResponse
    {
        $tag = $saveTag($this->actor($request), null, $request->input('name'), $request->input('color'));

        return (new WhatsappTagResource($tag))->response()->setStatusCode(201);
    }

    /**
     * `PATCH /api/v1/whatsapp/tags/{tag}`: `{ name, color }`.
     *
     * @throws ValidationException
     */
    public function update(Request $request, WhatsappTag $tag, SaveWhatsappTag $saveTag): WhatsappTagResource
    {
        return new WhatsappTagResource($saveTag($this->actor($request), $tag, $request->input('name', $tag->name), $request->input('color', $tag->color)));
    }

    /**
     * `DELETE /api/v1/whatsapp/tags/{tag}`.
     */
    public function destroy(Request $request, WhatsappTag $tag, DeleteWhatsappTag $deleteTag): Response
    {
        $deleteTag($this->actor($request), $tag);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
