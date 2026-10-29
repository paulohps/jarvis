<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ReportsAiUsage;
use App\Exceptions\AiAnswerCutShort;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Ai\AiRoute;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatParticipantData;
use App\Services\Chats\ChatProviderDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Stringable;

/**
 * Drafts a message to a conversation of the chat screen as its owner would
 * write it, whatever the channel, only when the owner asks for it
 * (DraftChatReply). Through the active AI provider connection, like
 * WhatsappReplyAgent, which it follows: the rules and the owner's messages
 * in their other conversations on the channel (ChatProviderDriver::
 * styleSamples()) are the instructions, cached by the provider; the
 * conversation's latest lines (CONTEXT_MESSAGES), the owner's messages in
 * it, the messages to answer and the time go in the prompt.
 *
 * On a channel with mentions, the people the owner's request mentions by
 * `@handle` are listed so the message mentions them the same way.
 */
#[CacheInstructions]
#[MaxTokens(self::MAX_TOKENS)]
#[Timeout(60)]
class ChatReplyAgent implements Agent, ReportsAiUsage
{
    use Promptable;

    /**
     * The most tokens an answer takes: a model that reasons spends this
     * same budget thinking first (WhatsappReplyAgent::MAX_TOKENS). Only what
     * is used is paid for.
     */
    public const int MAX_TOKENS = 8000;

    public const int CONTEXT_MESSAGES = 30;

    public const int STYLE_SAMPLES = 40;

    private const int SAMPLE_LENGTH = 300;

    private ?ChatConversation $conversation = null;

    public function __construct(
        public User $user,
        public AiRoute $route,
        private readonly ChatProviderDriver $driver,
    ) {}

    /**
     * @throws AiProviderNotConfigured when nothing was picked for it and no connection is in use
     */
    public static function forUser(User $user, ChatProviderDriver $driver): self
    {
        return new self($user, AiRoute::for(AiPurpose::CHAT_REPLY) ?? throw new AiProviderNotConfigured, $driver);
    }

    /**
     * One answer to the messages, in the owner's style.
     *
     * @param  list<ChatMessageData>  $messages
     */
    public function draft(ChatConversation $conversation, array $messages, ?string $instruction = null): string
    {
        if ($messages === []) {
            throw new InvalidArgumentException('A draft needs at least one message to answer.');
        }

        usort($messages, fn (ChatMessageData $a, ChatMessageData $b): int => $a->sentAt <=> $b->sentAt);

        $this->conversation = $conversation;
        $who = $conversation->displayName();
        $mentioned = $this->mentioned($conversation, (string) $instruction);

        $sections = [
            ...$this->context($conversation, $messages[array_key_last($messages)]),
            ...$this->toAnswer($messages, $who),
        ];

        if (filled($instruction)) {
            $sections[] = "What {$this->user->name} wants the reply to say: ".trim((string) $instruction);
            array_push($sections, ...$this->mentionsSection($mentioned));
        }

        $sections[] = $this->now();
        $sections[] = "Write {$this->user->name}'s reply.";

        return trim($this->answer(implode("\n\n", $sections))->text);
    }

    /**
     * A new message to the conversation, saying what the owner asks,
     * without reading it.
     */
    public function compose(ChatConversation $conversation, string $instruction): string
    {
        $this->conversation = $conversation;
        $mentioned = $this->mentioned($conversation, $instruction);

        $response = $this->answer(implode("\n\n", [
            "There is no message to answer: write a new message from {$this->user->name} to {$conversation->displayName()} on {$conversation->provider->name}.",
            "What {$this->user->name} wants the message to say: ".trim($instruction),
            ...$this->mentionsSection($mentioned),
            $this->now(),
            "Write {$this->user->name}'s message.",
        ]));

        return trim($response->text);
    }

    /**
     * A message the owner scheduled, written as it goes out, saying what
     * they asked, after the conversation's latest lines, so that a
     * repeating one says it anew each time.
     */
    public function writeScheduled(ChatConversation $conversation, string $instruction): string
    {
        $this->conversation = $conversation;
        $who = $conversation->displayName();
        $lines = $this->driver->messages($conversation, null, self::CONTEXT_MESSAGES)
            ->filter(fn (ChatMessageData $line): bool => $line->kind !== 'system' && ! $line->isDeleted())
            ->values();

        $response = $this->answer(implode("\n\n", [
            "The conversation with {$who} on {$conversation->provider->name}, oldest first:",
            $lines->isEmpty() ? '(no messages yet)' : $lines->map(fn (ChatMessageData $line): string => $this->line($line, $who))->implode("\n"),
            "{$this->user->name} scheduled a message to this conversation, and it goes out now. What it should say: ".trim($instruction),
            "Do not repeat a message {$this->user->name} already sent above: say it anew.",
            ...$this->mentionsSection($this->mentioned($conversation, $instruction)),
            $this->now(),
            "Write {$this->user->name}'s message.",
        ]));

        return trim($response->text);
    }

    public function instructions(): Stringable|string
    {
        $rules = implode("\n", [
            "You write chat messages on behalf of {$this->user->name}, so that the other people cannot tell it was not them.",
            '- Copy their style from the samples: length, punctuation, capital letters, emojis, slang, greetings and sign-offs. When they write short, write short.',
            '- Answer the messages you are given, all of them in a single reply, in the language of the conversation.',
            '- Never invent facts, promise anything, confirm dates, times, money or commitments the conversation does not already settle. When the answer needs that, reply in a neutral way that commits to nothing (e.g. say you will check and get back).',
            '- When the owner says what the reply must say, say exactly that, in their style.',
            '- Output only the message text: no quotes, no explanations, no options, no markup other than what the samples use.',
            '- The prompt gives the current date and time ("Now is …").',
        ]);

        if ($this->conversation === null) {
            return $rules;
        }

        return implode("\n\n", [
            $rules,
            "Style samples: messages {$this->user->name} sent to other people on {$this->conversation->provider->name}:",
            $this->samples($this->driver->styleSamples($this->user, $this->conversation, self::STYLE_SAMPLES)),
        ]);
    }

    public function usageConnection(): AiProviderConnection
    {
        return $this->route->connection;
    }

    public function usagePurpose(): ?string
    {
        return $this->route->purpose;
    }

    public function usageUser(): ?User
    {
        return $this->user;
    }

    /**
     * @return list<Provider>
     */
    public function provider(): array
    {
        return [Ai::build($this->route->providerConfig())];
    }

    /**
     * The model's answer to the prompt, never one cut short.
     *
     * @throws AiAnswerCutShort when the model ran out of tokens before the end
     */
    private function answer(string $prompt): AgentResponse
    {
        $response = $this->prompt($prompt);

        if ($response->steps->last()?->finishReason === FinishReason::Length) {
            throw new AiAnswerCutShort($response->meta->model ?? $this->route->model, $response->usage->outputTokens, $response->usage->reasoningTokens ?? 0);
        }

        return $response;
    }

    /**
     * The owner's messages in the conversation as style samples and its
     * latest lines up to the given message, oldest first.
     *
     * @return list<string>
     */
    private function context(ChatConversation $conversation, ChatMessageData $upTo): array
    {
        $who = $conversation->displayName();
        $lines = $this->driver->messages($conversation, null, self::CONTEXT_MESSAGES)
            ->filter(fn (ChatMessageData $line): bool => $line->sentAt->lessThanOrEqualTo($upTo->sentAt) && $line->kind !== 'system' && ! $line->isDeleted())
            ->values();

        $own = $lines->filter(fn (ChatMessageData $line): bool => $line->fromMe && filled($line->text))
            ->map(fn (ChatMessageData $line): string => (string) $line->text)
            ->reverse()
            ->take(self::STYLE_SAMPLES)
            ->values()
            ->all();
        $own = array_values($own);

        return [
            "Style samples: messages {$this->user->name} sent in this conversation:",
            $this->samples($own),
            "The conversation with {$who} on {$conversation->provider->name}, oldest first:",
            $lines->isEmpty() ? '(no messages yet)' : $lines->map(fn (ChatMessageData $line): string => $this->line($line, $who))->implode("\n"),
        ];
    }

    /**
     * @param  non-empty-list<ChatMessageData>  $messages
     * @return list<string>
     */
    private function toAnswer(array $messages, string $who): array
    {
        $author = fn (ChatMessageData $message): string => $message->fromMe ? $this->user->name : ($message->senderName ?? $who);

        if (count($messages) === 1) {
            return ['Message to answer (from '.$author($messages[0]).'): '.($messages[0]->text ?? $messages[0]->preview())];
        }

        return [implode("\n", [
            'Messages to answer together, oldest first:',
            ...array_map(fn (ChatMessageData $message): string => '- '.$author($message).': '.($message->text ?? $message->preview()), $messages),
        ])];
    }

    /**
     * The people of the conversation the owner's request mentions by
     * `@handle`, when the channel has mentions.
     *
     * @return list<ChatParticipantData>
     */
    private function mentioned(ChatConversation $conversation, string $instruction): array
    {
        if (! str_contains($instruction, '@') || ! $this->driver->capabilities($conversation)->mentions) {
            return [];
        }

        preg_match_all('/(?<![\w@])@([\w.\-]+)/u', $instruction, $matches);
        $handles = array_map('mb_strtolower', $matches[1]);

        if ($handles === []) {
            return [];
        }

        $mentioned = $this->driver->participants($conversation)
            ->filter(fn (ChatParticipantData $participant): bool => $participant->handle !== null && in_array(mb_strtolower($participant->handle), $handles, true))
            ->values()
            ->all();

        return array_values($mentioned);
    }

    /**
     * @param  list<ChatParticipantData>  $mentioned
     * @return list<string>
     */
    private function mentionsSection(array $mentioned): array
    {
        if ($mentioned === []) {
            return [];
        }

        return [implode("\n", [
            "People {$this->user->name} mentions, to mention in the message by writing @ and their handle exactly as below:",
            ...array_map(fn (ChatParticipantData $participant): string => "- @{$participant->handle} ({$participant->name})", $mentioned),
        ])];
    }

    private function now(): string
    {
        return 'Now is '.CarbonImmutable::now($this->user->timezone)->format('l, Y-m-d H:i')." ({$this->user->timezone}).";
    }

    private function line(ChatMessageData $message, string $who): string
    {
        $author = $message->fromMe ? $this->user->name : ($message->senderName ?? $who);
        $sentAt = $message->sentAt->setTimezone($this->user->timezone)->format('Y-m-d H:i');

        return "[{$sentAt}] {$author}: ".Str::limit($message->text ?? $message->preview(), self::SAMPLE_LENGTH);
    }

    /**
     * @param  list<string>  $bodies
     */
    private function samples(array $bodies): string
    {
        if ($bodies === []) {
            return '(none)';
        }

        return implode("\n", array_map(fn (string $body): string => '- '.Str::limit(str_replace("\n", ' ', $body), self::SAMPLE_LENGTH), $bodies));
    }
}
