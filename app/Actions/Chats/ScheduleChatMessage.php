<?php

namespace App\Actions\Chats;

use App\Actions\Recurrences\SaveRecurrence;
use App\Actions\Whatsapp\SendWhatsappChatContent;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\AiPurpose;
use App\Models\ChatConversation;
use App\Models\ChatScheduledMessage;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Services\Ai\AiRoute;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Schedules a message to a conversation, in the thread of a message when
 * `thread_id` is given, as the user: once or repeating, as `recurrence`
 * says (the input of SaveRecurrence, read in the user's timezone), or once
 * at `send_at` without it (`Y-m-d H:i` or the browser's `Y-m-dTH:i`). The
 * text is `body`, or, with `ai_prompt`, what the AI writes at each sending
 * from it (SendChatScheduledMessage), which needs an AI set up for the chat
 * replies. Or, with `poll` (`question`, `options`, `multiple`; the rules
 * of SendWhatsappChatContent::pollOf(), errors under `poll.*`), that poll,
 * where the provider has polls; its question is kept as the body. Refused
 * when the provider cannot send to the conversation.
 *
 * Errors come under the input's keys (`recurrence.starts_at`…).
 */
final readonly class ScheduleChatMessage
{
    use AuthorizesChats;

    public function __construct(
        private ChatProviderRegistry $registry,
        private SaveRecurrence $saveRecurrence,
    ) {}

    /**
     * @param  array{body?: mixed, ai_prompt?: mixed, poll?: mixed, thread_id?: mixed, send_at?: mixed, recurrence?: mixed}  $input
     *
     * @throws AuthorizationException
     * @throws AiProviderNotConfigured
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, array $input): ChatScheduledMessage
    {
        $this->authorizeConversation($actor, $conversation);

        $capabilities = $this->registry->for($conversation)->capabilities($conversation);

        if (! $capabilities->send) {
            throw ValidationException::withMessages(['body' => __('Nothing can be sent to this conversation right now.')]);
        }

        $aiPrompt = is_string($input['ai_prompt'] ?? null) && filled($input['ai_prompt']) ? mb_trim($input['ai_prompt']) : null;
        $sendAt = $input['send_at'] ?? null;
        $poll = $this->poll($input['poll'] ?? null, $capabilities->polls, $aiPrompt !== null);

        /** @var array{body: string|null, ai_prompt: string|null, thread_id: string|null, send_at: string|null} $validated */
        $validated = Validator::make(
            [
                'body' => $poll !== null ? $poll['name'] : ($aiPrompt === null && is_string($input['body'] ?? null) && filled($input['body']) ? mb_trim($input['body']) : null),
                'ai_prompt' => $aiPrompt,
                'thread_id' => $input['thread_id'] ?? null,
                'send_at' => is_string($sendAt) ? str_replace('T', ' ', mb_substr($sendAt, 0, 16)) : $sendAt,
                'recurrence' => $input['recurrence'] ?? null,
            ],
            [
                'body' => [$aiPrompt === null ? 'required' : 'nullable', 'string', 'max:'.ChatScheduledMessage::MAX_BODY_LENGTH],
                'ai_prompt' => ['nullable', 'string', 'max:'.ChatScheduledMessage::MAX_AI_PROMPT_LENGTH],
                'thread_id' => ['nullable', 'string', $capabilities->threads ? 'nullable' : 'prohibited'],
                'recurrence' => ['nullable', 'array'],
                'send_at' => [isset($input['recurrence']) ? 'nullable' : 'required', 'date_format:Y-m-d H:i'],
            ],
            attributes: ['body' => __('message'), 'ai_prompt' => __('prompt'), 'send_at' => __('date and time')],
        )->validate();

        if ($aiPrompt !== null && AiRoute::for(AiPurpose::CHAT_REPLY) === null) {
            throw new AiProviderNotConfigured;
        }

        /** @var array<string, mixed> $recurrence */
        $recurrence = $input['recurrence'] ?? ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => $validated['send_at']];

        return DB::transaction(function () use ($actor, $conversation, $validated, $poll, $recurrence, $input): ChatScheduledMessage {
            $scheduled = ChatScheduledMessage::query()->create([
                'chat_conversation_id' => $conversation->id,
                'user_id' => $actor->id,
                'body' => $validated['body'],
                'ai_prompt' => $validated['ai_prompt'],
                'poll' => $poll,
                'thread_id' => $validated['thread_id'],
            ]);
            $scheduled->setRelation('user', $actor);

            try {
                ($this->saveRecurrence)($scheduled, $recurrence);
            } catch (ValidationException $exception) {
                $field = isset($input['recurrence']) ? 'recurrence.' : '';
                $errors = [];

                foreach ($exception->errors() as $key => $messages) {
                    $errors[$field === '' && $key === 'starts_at' ? 'send_at' : $field.$key] = $messages;
                }

                throw ValidationException::withMessages($errors);
            }

            return $scheduled->load('recurrence.frequency');
        });
    }

    /**
     * The poll to send, validated, or null for a text.
     *
     * @return array{name: string, options: list<string>, selectable_count: int}|null
     *
     * @throws ValidationException
     */
    private function poll(mixed $poll, bool $providerHasPolls, bool $writesWithAi): ?array
    {
        if ($poll === null || $poll === []) {
            return null;
        }

        if (! $providerHasPolls) {
            throw ValidationException::withMessages(['poll' => __('Polls cannot be sent to this conversation.')]);
        }

        if ($writesWithAi || ! is_array($poll)) {
            throw ValidationException::withMessages(['poll' => __('A poll is not written by the AI. Turn one of them off.')]);
        }

        try {
            return SendWhatsappChatContent::pollOf(
                $poll['question'] ?? null,
                is_array($poll['options'] ?? null) ? $poll['options'] : [],
                filter_var($poll['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors["poll.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
