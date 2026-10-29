<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\DraftWhatsappReply;
use App\Exceptions\AiProviderNotConfigured;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappChatMessageResource;
use App\Http\Resources\WhatsappChatResource;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The reply keyboard of the apps: the user's one-to-one chats, latest
 * received first, their messages, and an answer drafted by the AI in the
 * user's style, only when asked. The keyboard inserts the draft in the
 * text field; sending stays with the user, in WhatsApp itself.
 */
class WhatsappChatController extends Controller
{
    public const int CHATS_PER_PAGE = 20;

    public const int MAX_CHATS_PER_PAGE = 50;

    public const int MESSAGES_SHOWN = 20;

    /**
     * `GET /api/v1/whatsapp/chats`: the one-to-one chats of all the user's
     * accounts where the contact sent something (groups stay on the
     * WhatsApp screen), the latest received first, paginated (`per_page` up
     * to 50).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->actor($request);
        $perPage = min(max($request->integer('per_page', self::CHATS_PER_PAGE), 1), self::MAX_CHATS_PER_PAGE);

        return WhatsappChatResource::collection(WhatsappTarget::query()
            ->whereHas('account', fn (Builder $query) => $query->where('user_id', $user->id))
            ->where('whatsapp_target_type_id', WhatsappTargetType::idFor(WhatsappTargetType::CONTACT))
            ->whereHas('chatMessages', fn (Builder $query) => $query->where('from_me', false))
            ->addSelect(['last_received_at' => WhatsappChatMessage::query()
                ->selectRaw('max(sent_at)')
                ->whereColumn('whatsapp_target_id', 'whatsapp_targets.id')
                ->where('from_me', false)])
            ->with(['account', 'lastChatMessage', 'lastReceivedChatMessage'])
            ->orderByDesc('last_received_at')
            ->orderByDesc('id')
            ->paginate($perPage));
    }

    /**
     * `GET /api/v1/whatsapp/chats/{chat}/messages`: the latest messages of
     * the chat (MESSAGES_SHOWN), newest first, both ways.
     */
    public function messages(WhatsappTarget $chat): AnonymousResourceCollection
    {
        Gate::authorize('view', $chat->account);

        return WhatsappChatMessageResource::collection($chat->chatMessages()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_SHOWN)
            ->get());
    }

    /**
     * `POST /api/v1/whatsapp/chat-messages/{message}/reply`: `{ instruction? }`,
     * what the answer must say, in the user's words. Answers `{ text }`,
     * the draft. A message the user sent is a 422; no active AI provider
     * connection is a 503; a provider failing is a 502.
     *
     * @throws AiProviderNotConfigured
     * @throws ValidationException
     */
    public function reply(Request $request, WhatsappChatMessage $message, DraftWhatsappReply $draftReply): JsonResponse
    {
        try {
            $text = $draftReply($this->actor($request), $message, $request->string('instruction')->toString());
        } catch (AuthorizationException|ValidationException|AiProviderNotConfigured $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new HttpException(502, __('The assistant could not write a reply right now. Try again in a moment.'), $exception);
        }

        return response()->json(['text' => $text]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
