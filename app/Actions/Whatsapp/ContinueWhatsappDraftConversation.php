<?php

namespace App\Actions\Whatsapp;

use App\Ai\Agents\WhatsappReplyAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Jobs\NameWhatsappDraftConversation;
use App\Models\User;
use App\Models\WhatsappDraftConversation;
use App\Models\WhatsappDraftMessage;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Asks the AI for a message to the chat, or for a change to the last one it
 * wrote, in a conversation the owner can go back to
 * (WhatsappDraftConversation, WhatsappReplyAgent::converse()). The first
 * request starts the conversation, titled with its start until the AI
 * names it (NameWhatsappDraftConversation). `readsChat` and `replyTo` (the
 * friendly ids of the picked messages, up to
 * DraftWhatsappReply::MAX_MESSAGES) say what of the chat the AI reads from
 * then on. Nothing is sent: the owner uses the message they like.
 *
 * Only the owner of the account, with the `whatsapp` module, in their own
 * conversations. When the AI fails, the request is taken back and what it
 * threw is left to the caller.
 */
final readonly class ContinueWhatsappDraftConversation
{
    use AuthorizesWhatsappModule;

    /**
     * @param  list<string>  $replyTo
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws AiProviderNotConfigured
     */
    public function __invoke(
        User $actor,
        WhatsappTarget $chat,
        ?WhatsappDraftConversation $conversation,
        ?string $request,
        bool $readsChat = true,
        array $replyTo = [],
    ): WhatsappDraftMessage {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('view', $chat->account);

        if ($conversation !== null && ($conversation->user_id !== $actor->id || $conversation->whatsapp_target_id !== $chat->id)) {
            throw new AuthorizationException;
        }

        /** @var array{request: string, reply_to: list<string>} $validated */
        $validated = Validator::make(
            ['request' => filled($request) ? mb_trim((string) $request) : null, 'reply_to' => array_values(array_unique($replyTo))],
            [
                'request' => ['required', 'string', 'max:'.DraftWhatsappReply::MAX_INSTRUCTION],
                'reply_to' => ['array', 'max:'.DraftWhatsappReply::MAX_MESSAGES],
                'reply_to.*' => ['string'],
            ],
            [
                'request.required' => __('Say what the message must say.'),
                'request.max' => __('The request can have up to :max characters.'),
            ],
        )->validate();

        $replyTo = $chat->chatMessages()->whereIn('friendly_id', $validated['reply_to'])->pluck('friendly_id')->all();
        $isNew = $conversation === null;

        $conversation ??= new WhatsappDraftConversation([
            'user_id' => $actor->id,
            'whatsapp_target_id' => $chat->id,
            'title' => Str::limit($validated['request'], 60),
        ]);
        $conversation->fill(['reads_chat' => $readsChat, 'reply_to' => $replyTo === [] ? null : $replyTo])->save();

        $asked = $conversation->messages()->create(['role' => WhatsappDraftMessage::USER, 'body' => $validated['request']]);

        try {
            $text = WhatsappReplyAgent::forUser($actor)->converse($conversation);
        } catch (Throwable $exception) {
            $isNew ? $conversation->delete() : $asked->delete();

            throw $exception;
        }

        $written = $conversation->messages()->create(['role' => WhatsappDraftMessage::ASSISTANT, 'body' => $text]);
        $conversation->touch();

        if ($isNew) {
            NameWhatsappDraftConversation::dispatchAfterResponse($conversation);
        }

        return $written;
    }
}
