<?php

namespace App\Actions\Whatsapp;

use App\Ai\Agents\WhatsappReplyAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Drafts one answer to one or more messages of a chat (up to MAX_MESSAGES,
 * at least one the contact sent), in the owner's style
 * (WhatsappReplyAgent), for the reply keyboard of the apps and the WhatsApp
 * screen. Nothing is sent: the draft goes into the text being typed.
 * `instruction`, up to MAX_INSTRUCTION characters, says what the answer
 * must say; in a group, the people it mentions (`@` and their number) are
 * mentioned in the answer too.
 *
 * Only the owner of the account, with the `whatsapp` module. What the AI
 * provider throws is left to the caller.
 */
final readonly class DraftWhatsappReply
{
    use AuthorizesWhatsappModule;

    public const int MAX_INSTRUCTION = 2000;

    public const int MAX_MESSAGES = 20;

    /**
     * @param  WhatsappChatMessage|list<WhatsappChatMessage>  $messages
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws AiProviderNotConfigured
     */
    public function __invoke(User $actor, WhatsappChatMessage|array $messages, ?string $instruction = null): string
    {
        $messages = is_array($messages) ? array_values($messages) : [$messages];

        if ($messages === [] || count($messages) > self::MAX_MESSAGES) {
            throw ValidationException::withMessages(['message' => __('Pick from 1 to :max messages.', ['max' => self::MAX_MESSAGES])]);
        }

        $chatId = $messages[0]->whatsapp_target_id;

        if (array_any($messages, fn (WhatsappChatMessage $message): bool => $message->whatsapp_target_id !== $chatId)) {
            throw ValidationException::withMessages(['message' => __('Pick messages of the same chat.')]);
        }

        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('view', $messages[0]->target->account);

        /** @var array{instruction: string|null} $validated */
        $validated = Validator::make(
            ['instruction' => filled($instruction) ? mb_trim((string) $instruction) : null],
            ['instruction' => ['nullable', 'string', 'max:'.self::MAX_INSTRUCTION]],
        )->validate();

        if (array_all($messages, fn (WhatsappChatMessage $message): bool => $message->from_me)) {
            throw ValidationException::withMessages(['message' => __('Pick a message the contact sent.')]);
        }

        return WhatsappReplyAgent::forUser($actor)->draft($messages, $validated['instruction']);
    }

    /**
     * A new message to the chat from the `instruction` alone, without its
     * messages (WhatsappReplyAgent::compose()).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws AiProviderNotConfigured
     */
    public function fromPrompt(User $actor, WhatsappTarget $chat, ?string $instruction): string
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('view', $chat->account);

        /** @var array{instruction: string} $validated */
        $validated = Validator::make(
            ['instruction' => filled($instruction) ? mb_trim((string) $instruction) : null],
            ['instruction' => ['required', 'string', 'max:'.self::MAX_INSTRUCTION]],
            ['instruction.required' => __('Say what the message must say.')],
        )->validate();

        return WhatsappReplyAgent::forUser($actor)->compose($chat, $validated['instruction']);
    }
}
