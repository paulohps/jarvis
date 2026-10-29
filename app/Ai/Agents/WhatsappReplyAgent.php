<?php

namespace App\Ai\Agents;

use App\Actions\Whatsapp\SendWhatsappChatMessage;
use App\Ai\Contracts\ReportsAiUsage;
use App\Exceptions\AiAnswerCutShort;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDraftConversation;
use App\Models\WhatsappDraftMessage;
use App\Models\WhatsappTarget;
use App\Services\Ai\AiRoute;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Stringable;

/**
 * Drafts the answer to a WhatsApp message as its owner would write it, for
 * the reply keyboard of the apps, only when the owner asks for it. Through
 * the active AI provider connection, like VoiceCommandAgent.
 *
 * In a group chat, each line and the message to answer name who in the
 * group sent it.
 *
 * The prompt carries the chat up to the message (CONTEXT_MESSAGES), the
 * owner's own messages in this chat as style samples and, when given, what
 * the owner wants the answer to say; the instructions carry the owner's
 * messages in their other chats (STYLE_SAMPLES each). The reply is the text
 * only, ready to send.
 *
 * `compose()` writes a new message from the owner's prompt alone: nothing
 * of the chat goes in (no lines, no message to answer, no samples from it),
 * only the contact's name and the owner's style from their other chats.
 *
 * `writeScheduled()` writes a scheduled message when it goes out, from the
 * owner's prompt, reading the chat's latest lines (WriteScheduledWhatsappMessage).
 *
 * What stays the same from one call to the next on a chat goes first: the
 * rules and the samples from the owner's other chats are the instructions,
 * the current time goes at the end of the prompt. The provider's prompt
 * cache (explicit on Anthropic, automatic on OpenAI, DeepSeek, Gemini…) can
 * then reuse them while the owner drafts in the same chat.
 *
 * In a group, the members the owner's prompt mentions (`@` and their
 * number, as the composer writes them) are named in the prompt, and the
 * message mentions them the same way, so that WhatsApp mentions them when
 * it is sent (SendWhatsappChatMessage::mentionsIn()). When the model writes
 * `@` and one of their names instead, the name is turned into the number
 * (withMentions()).
 */
#[CacheInstructions]
#[MaxTokens(self::MAX_TOKENS)]
#[Timeout(60)]
class WhatsappReplyAgent implements Agent, Conversational, ReportsAiUsage
{
    use Promptable;

    /**
     * The most tokens an answer takes: a WhatsApp message of 4096
     * characters is about 1,500, and a model that reasons spends this same
     * budget thinking first. Only what is used is paid for.
     */
    public const int MAX_TOKENS = 8000;

    public const int CONTEXT_MESSAGES = 30;

    public const int STYLE_SAMPLES = 40;

    private const int SAMPLE_LENGTH = 300;

    /**
     * The chat being answered or written to: the instructions carry the
     * owner's samples from the other ones.
     */
    private ?WhatsappTarget $chat = null;

    /**
     * The earlier turns of a conversation with the owner (converse()).
     *
     * @var list<Message>
     */
    private array $history = [];

    public function __construct(
        public User $user,
        public AiRoute $route,
    ) {}

    /**
     * The drafter for the user, through the connection picked for it.
     *
     * @throws AiProviderNotConfigured when nothing was picked for it and no connection is in use
     */
    public static function forUser(User $user): self
    {
        return new self($user, AiRoute::for(AiPurpose::WHATSAPP_REPLY) ?? throw new AiProviderNotConfigured);
    }

    /**
     * One answer to the messages (of the same chat), in the owner's style.
     *
     * @param  WhatsappChatMessage|list<WhatsappChatMessage>  $messages
     */
    public function draft(WhatsappChatMessage|array $messages, ?string $instruction = null): string
    {
        $messages = is_array($messages) ? $messages : [$messages];

        if ($messages === []) {
            throw new InvalidArgumentException('A draft needs at least one message to answer.');
        }

        usort($messages, fn (WhatsappChatMessage $a, WhatsappChatMessage $b): int => [$a->sent_at->getTimestamp(), $a->id] <=> [$b->sent_at->getTimestamp(), $b->id]);

        $this->chat = $messages[array_key_last($messages)]->target;
        $mentioned = $this->mentioned($this->chat, (string) $instruction);
        $response = $this->answer($this->promptFor($messages, $instruction, $mentioned));

        return $this->withMentions(trim($response->text), $mentioned);
    }

    /**
     * A new message to the chat, saying what the owner asks, in their style,
     * without reading the chat.
     */
    public function compose(WhatsappTarget $chat, string $instruction): string
    {
        $this->chat = $chat;
        $mentioned = $this->mentioned($chat, $instruction);
        $response = $this->answer(implode("\n\n", [
            "There is no message to answer: write a new message from {$this->user->name} to {$chat->displayName()}.",
            "What {$this->user->name} wants the message to say: ".trim($instruction),
            ...$this->mentionsSection($mentioned),
            $this->now(),
            "Write {$this->user->name}'s message.",
        ]));

        return $this->withMentions(trim($response->text), $mentioned);
    }

    /**
     * A message scheduled to the chat, written when it goes out: saying
     * what the owner's prompt asks, in their style, with the chat's latest
     * lines to fit what was said and not to repeat an earlier message of a
     * schedule that runs again.
     */
    public function writeScheduled(WhatsappTarget $chat, string $instruction): string
    {
        $this->chat = $chat;
        $mentioned = $this->mentioned($chat, $instruction);
        $response = $this->answer(implode("\n\n", [
            ...$this->chatContext($chat, null),
            "There is no message to answer: {$this->user->name} scheduled a message to {$chat->displayName()}, sent now, maybe again on later days. Do not write the same as an earlier message of theirs in the chat.",
            "What {$this->user->name} wants the message to say: ".trim($instruction),
            ...$this->mentionsSection($mentioned),
            $this->now(),
            "Write {$this->user->name}'s message.",
        ]));

        return $this->withMentions(trim($response->text), $mentioned);
    }

    /**
     * The next message of a conversation with the owner about what to write
     * in its chat: its earlier requests and messages are the conversation
     * (the AI's as the owner left them after editing them), the latest
     * request is the prompt, with the chat when the conversation reads it
     * (its latest lines, the picked messages or the ones the contact sent
     * since the owner's last one) or without it.
     */
    public function converse(WhatsappDraftConversation $conversation): string
    {
        $turns = $conversation->messages()->get()->all();
        $request = array_pop($turns);

        if (! $request instanceof WhatsappDraftMessage || $request->isFromAssistant()) {
            throw new InvalidArgumentException('A conversation goes on from a request of the owner.');
        }

        $chat = $conversation->chat;
        $this->chat = $chat;
        $this->history = array_values(array_map(fn (WhatsappDraftMessage $turn): Message => new Message($turn->role, $turn->body), $turns));
        $lastWritten = collect($turns)->last(fn (WhatsappDraftMessage $turn): bool => $turn->isFromAssistant());
        $mentioned = $this->mentioned($chat, implode("\n", [...array_map(fn (WhatsappDraftMessage $turn): string => $turn->body, $turns), $request->body]));
        $contact = $chat->displayName();

        $sections = [];

        if ($conversation->reads_chat) {
            $picked = $conversation->reply_to !== null && $conversation->reply_to !== []
                ? $chat->chatMessages()->whereIn('friendly_id', $conversation->reply_to)->orderBy('sent_at')->orderBy('id')->get()->all()
                : $chat->latestReceivedChatMessages();

            array_push($sections, ...$this->chatContext($chat, $picked !== [] ? $picked[array_key_last($picked)] : null));

            if ($picked !== []) {
                array_push($sections, ...$this->toAnswer(array_values($picked), $contact));
            }
        } else {
            $sections[] = "Do not read into the chat: write a new message from {$this->user->name} to {$contact}.";
        }

        if ($lastWritten?->edited_at !== null) {
            $sections[] = "{$this->user->name} edited your last message by hand; the version in the conversation is theirs: keep their changes.";
        }

        $sections[] = "What {$this->user->name} asks now: ".trim($request->body);
        array_push($sections, ...$this->mentionsSection($mentioned));
        $sections[] = $this->now();
        $sections[] = $turns === []
            ? "Write {$this->user->name}'s message."
            : "Write the whole message again with what {$this->user->name} asks now.";

        $response = $this->answer(implode("\n\n", $sections));

        return $this->withMentions(trim($response->text), $mentioned);
    }

    /**
     * The earlier turns of the conversation (converse()), none for a single
     * draft.
     *
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    /**
     * The owner's own messages in their other chats, latest first.
     *
     * @return array<int, string>
     */
    private function otherChatSamples(WhatsappTarget $chat): array
    {
        return WhatsappChatMessage::query()
            ->where('from_me', true)
            ->whereNotNull('body')
            ->where('whatsapp_target_id', '!=', $chat->id)
            ->whereHas('target.account', fn (Builder $query) => $query->where('user_id', $this->user->id))
            ->latest('sent_at')
            ->limit(self::STYLE_SAMPLES)
            ->pluck('body')
            ->all();
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $rules = implode("\n", [
            "You write WhatsApp replies on behalf of {$this->user->name}, so that the contact cannot tell it was not them.",
            '- Copy their style from the samples: length, punctuation, capital letters, emojis, slang, greetings and sign-offs. When they write short, write short.',
            '- Answer the messages you are given, all of them in a single reply, in the language of the chat.',
            '- Never invent facts, promise anything, confirm dates, times, money or commitments the chat does not already settle. When the answer needs that, reply in a neutral way that commits to nothing (e.g. say you will check and get back).',
            '- When the owner says what the reply must say, say exactly that, in their style.',
            '- In a conversation with the owner, each request after the first is about the last message you wrote, as they may have left it after editing it: write it whole again with the change, keeping the rest.',
            '- Output only the message text: no quotes, no explanations, no options.',
            '- The prompt gives the current date and time ("Now is …").',
        ]);

        if ($this->chat === null) {
            return $rules;
        }

        return implode("\n\n", [
            $rules,
            "Style samples: messages {$this->user->name} sent to other people:",
            $this->samples($this->otherChatSamples($this->chat)),
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
     * The connection picked for this use, built on demand from the stored
     * credentials.
     *
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
     * @param  non-empty-list<WhatsappChatMessage>  $messages  oldest first
     * @param  list<array{number: string, name: string|null}>  $mentioned
     */
    private function promptFor(array $messages, ?string $instruction, array $mentioned): string
    {
        $message = $messages[array_key_last($messages)];
        $target = $message->target;
        $contact = $target->displayName();

        $sections = [
            ...$this->chatContext($target, $message),
            ...$this->toAnswer($messages, $contact),
        ];

        if (filled($instruction)) {
            $sections[] = "What {$this->user->name} wants the reply to say: ".trim((string) $instruction);
            array_push($sections, ...$this->mentionsSection($mentioned));
        }

        $sections[] = $this->now();
        $sections[] = "Write {$this->user->name}'s reply.";

        return implode("\n\n", $sections);
    }

    /**
     * The owner's messages in the chat as style samples and the chat's latest
     * lines, up to the given message (else the latest one), oldest first.
     *
     * @return list<string>
     */
    private function chatContext(WhatsappTarget $target, ?WhatsappChatMessage $upTo): array
    {
        $contact = $target->displayName();

        $chat = $target->chatMessages()
            ->when($upTo !== null, fn (Builder $query) => $query->where('sent_at', '<=', $upTo->sent_at))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::CONTEXT_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn (WhatsappChatMessage $line): string => $this->line($line, $contact));

        $sameChat = $target->chatMessages()
            ->where('from_me', true)
            ->whereNotNull('body')
            ->latest('sent_at')
            ->limit(self::STYLE_SAMPLES)
            ->pluck('body');

        return [
            "Style samples: messages {$this->user->name} sent to {$contact}:",
            $this->samples($sameChat->all()),
            "The chat with {$contact}, oldest first:",
            $chat->isEmpty() ? '(no messages yet)' : $chat->implode("\n"),
        ];
    }

    /**
     * The message to answer, or the list of them.
     *
     * @param  non-empty-list<WhatsappChatMessage>  $messages
     * @return list<string>
     */
    private function toAnswer(array $messages, string $contact): array
    {
        $author = fn (WhatsappChatMessage $message): string => $message->from_me ? $this->user->name : ($message->senderLabel() ?? $contact);

        if (count($messages) === 1) {
            return ['Message to answer (from '.$author($messages[0]).'): '.$messages[0]->bodyOrPlaceholder()];
        }

        return [implode("\n", [
            'Messages to answer together, oldest first:',
            ...array_map(fn (WhatsappChatMessage $message): string => '- '.$author($message).': '.$message->bodyOrPlaceholder(), $messages),
        ])];
    }

    /**
     * The group members the owner's prompt mentions: their number, and
     * their name after the account's contact, else after what they last
     * went by in the group, else none.
     *
     * @return list<array{number: string, name: string|null}>
     */
    private function mentioned(WhatsappTarget $chat, string $instruction): array
    {
        $jids = $chat->isGroup() ? SendWhatsappChatMessage::mentionsIn($instruction) : [];

        if ($jids === []) {
            return [];
        }

        $contacts = $chat->account->targets()->whereIn('jid', $jids)->get()
            ->mapWithKeys(fn (WhatsappTarget $contact): array => [$contact->jid => $contact->displayName()])
            ->all();
        $senders = $chat->chatMessages()
            ->whereIn('sender_jid', $jids)
            ->whereNotNull('sender_name')
            ->orderBy('sent_at')
            ->pluck('sender_name', 'sender_jid')
            ->all();

        return array_map(fn (string $jid): array => [
            'number' => (string) strstr($jid, '@', true),
            'name' => $contacts[$jid] ?? $senders[$jid] ?? null,
        ], $jids);
    }

    /**
     * @param  list<array{number: string, name: string|null}>  $mentioned
     * @return list<string>
     */
    private function mentionsSection(array $mentioned): array
    {
        if ($mentioned === []) {
            return [];
        }

        return [implode("\n", [
            "People {$this->user->name} mentions, to mention in the message by writing @ and their number exactly as below (WhatsApp shows their name), never @ and their name:",
            ...array_map(fn (array $member): string => "- @{$member['number']}".($member['name'] !== null ? " ({$member['name']})" : ''), $mentioned),
        ])];
    }

    /**
     * The message with `@` and the name of a mentioned member turned into `@`
     * and their number: their full name first, then their first name when
     * no other mentioned member shares it. Case and accents of the name as
     * the model wrote them do not matter.
     *
     * @param  list<array{number: string, name: string|null}>  $mentioned
     */
    private function withMentions(string $text, array $mentioned): string
    {
        $names = [];
        $firstNames = [];

        foreach ($mentioned as $member) {
            if (blank($member['name'])) {
                continue;
            }

            $names[$member['name']] = $member['number'];
            $firstNames[Str::before($member['name'], ' ')][] = $member['number'];
        }

        foreach ($firstNames as $firstName => $numbers) {
            if (count($numbers) === 1 && ! isset($names[$firstName])) {
                $names[$firstName] = $numbers[0];
            }
        }

        uksort($names, fn (int|string $a, int|string $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        foreach ($names as $name => $number) {
            $pattern = '/(?<![\w@])@'.$this->namePattern((string) $name).'(?![\p{L}\p{N}])/iu';
            $text = (string) preg_replace($pattern, '@'.$number, $text);
        }

        return $text;
    }

    /**
     * The name as a pattern that matches it with or without accents and with
     * any spacing between its words.
     */
    private function namePattern(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        return implode('\s+', array_map(function (string $word): string {
            return implode('', array_map(function (string $letter): string {
                $plain = Str::ascii($letter);

                return $plain !== '' && $plain !== $letter && mb_strlen($plain) === 1
                    ? '['.preg_quote($letter, '/').preg_quote($plain, '/').']'
                    : preg_quote($letter, '/');
            }, mb_str_split($word)));
        }, $words));
    }

    /**
     * The current date and time in the owner's timezone.
     */
    private function now(): string
    {
        return 'Now is '.CarbonImmutable::now($this->user->timezone)->format('l, Y-m-d H:i')." ({$this->user->timezone}).";
    }

    private function line(WhatsappChatMessage $message, string $contact): string
    {
        $author = $message->from_me ? $this->user->name : ($message->senderLabel() ?? $contact);
        $sentAt = $message->sent_at->setTimezone($this->user->timezone)->format('Y-m-d H:i');

        return "[{$sentAt}] {$author}: ".Str::limit($message->bodyOrPlaceholder(), self::SAMPLE_LENGTH);
    }

    /**
     * @param  array<int, string>  $bodies
     */
    private function samples(array $bodies): string
    {
        if ($bodies === []) {
            return '(none)';
        }

        return implode("\n", array_map(fn (string $body): string => '- '.Str::limit(str_replace("\n", ' ', $body), self::SAMPLE_LENGTH), $bodies));
    }
}
