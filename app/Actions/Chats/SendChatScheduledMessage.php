<?php

namespace App\Actions\Chats;

use App\Ai\Agents\ChatReplyAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\ChatConversation;
use App\Models\ChatScheduledDelivery;
use App\Models\ChatScheduledMessage;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Sends one occurrence of a scheduled message (`$slot`, the instant it was
 * due; `send_at` for one without a recurrence), as its owner
 * (SendChatMessage, so a provider switched off for them since refuses
 * it): the text is the body or, from the prompt, what the AI writes now
 * (ChatReplyAgent::writeScheduled()), or its poll (SendChatPoll). Each
 * sending is kept, sent or failed
 * with why (ChatScheduledDelivery), and never tried again; one that goes
 * only once is marked sent or failed too.
 *
 * An occurrence already sent, a message cancelled or, going once, already
 * done, is left alone.
 */
final readonly class SendChatScheduledMessage
{
    public function __construct(
        private SendChatMessage $send,
        private SendChatPoll $sendPoll,
        private ChatProviderRegistry $registry,
    ) {}

    public function __invoke(ChatScheduledMessage $scheduled, ?CarbonImmutable $slot = null): ChatScheduledMessage
    {
        $slot ??= CarbonImmutable::instance($scheduled->send_at ?? now());

        if ($scheduled->cancelled_at !== null
            || (! $scheduled->repeats() && ($scheduled->sent_at !== null || $scheduled->failed_at !== null))
            || $scheduled->deliveries()->where('slot_at', $slot)->exists()) {
            return $scheduled;
        }

        $conversation = $scheduled->conversation->load(['provider', 'conversable']);

        try {
            $body = $scheduled->writesWithAi() ? $this->write($scheduled, $conversation) : (string) $scheduled->body;
        } catch (AiProviderNotConfigured) {
            return $this->record($scheduled, $conversation, $slot, null, __('No AI provider is set up. Ask the administrator.'));
        } catch (Throwable $exception) {
            report($exception);

            return $this->record($scheduled, $conversation, $slot, null, __('The AI could not write the message.'));
        }

        try {
            if ($scheduled->poll !== null) {
                ($this->sendPoll)($scheduled->user, $conversation, $scheduled->poll['name'], $scheduled->poll['options'], $scheduled->poll['selectable_count'] !== 1);
            } else {
                ($this->send)($scheduled->user, $conversation, $body, [], $scheduled->thread_id);
            }

            return $this->record($scheduled, $conversation, $slot, $body);
        } catch (AuthorizationException|ValidationException|ChatProviderException $exception) {
            $error = $exception instanceof ValidationException ? (string) collect($exception->errors())->flatten()->first() : $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $error = __('The message could not be sent.');
        }

        return $this->record($scheduled, $conversation, $slot, $body, $error);
    }

    /**
     * @throws AiProviderNotConfigured
     */
    private function write(ChatScheduledMessage $scheduled, ChatConversation $conversation): string
    {
        $text = ChatReplyAgent::forUser($scheduled->user, $this->registry->for($conversation))
            ->writeScheduled($conversation, (string) $scheduled->ai_prompt);

        if ($text === '') {
            throw new RuntimeException('The AI wrote an empty message.');
        }

        return mb_substr($text, 0, ChatScheduledMessage::MAX_BODY_LENGTH);
    }

    private function record(ChatScheduledMessage $scheduled, ChatConversation $conversation, CarbonImmutable $slot, ?string $body, ?string $error = null): ChatScheduledMessage
    {
        $scheduled->deliveries()->create([
            'user_id' => $scheduled->user_id,
            'chat_conversation_id' => $conversation->id,
            'conversation_name' => mb_substr($conversation->displayName(), 0, 200),
            'slot_at' => $slot,
            'body' => $body,
            'status' => $error === null ? ChatScheduledDelivery::SENT : ChatScheduledDelivery::FAILED,
            'error' => $error === null ? null : mb_substr($error, 0, ChatScheduledDelivery::MAX_ERROR_LENGTH),
        ]);

        if (! $scheduled->repeats()) {
            $scheduled->forceFill($error === null ? ['sent_at' => now()] : ['failed_at' => now(), 'error' => mb_substr($error, 0, 500)])->save();
        }

        return $scheduled;
    }
}
