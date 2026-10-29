<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\ReportsAiUsage;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\AiProviderConnection;
use App\Models\AiPurpose;
use App\Models\User;
use App\Models\WhatsappDraftConversation;
use App\Services\Ai\AiRoute;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Stringable;

/**
 * Names a conversation with the AI about what to write in a WhatsApp chat
 * from its first request, so that the history lists it by what it was
 * about. Through the connection picked for the WhatsApp replies.
 */
#[MaxTokens(2000)]
#[Timeout(20)]
class WhatsappDraftTitleAgent implements Agent, ReportsAiUsage
{
    use Promptable;

    public function __construct(
        public User $user,
        public AiRoute $route,
    ) {}

    /**
     * @throws AiProviderNotConfigured when nothing was picked for the WhatsApp replies and no connection is in use
     */
    public static function forUser(User $user): self
    {
        return new self($user, AiRoute::for(AiPurpose::WHATSAPP_REPLY) ?? throw new AiProviderNotConfigured);
    }

    /**
     * A short title for the conversation, null when the model gives none.
     */
    public function title(WhatsappDraftConversation $conversation): ?string
    {
        $request = $conversation->messages()->where('role', 'user')->value('body');

        if (! is_string($request) || trim($request) === '') {
            return null;
        }

        $response = $this->prompt("The request:\n".Str::limit($request, 1000));

        // Cut short (a model that reasons spends the same budget thinking), it is no title.
        if ($response->steps->last()?->finishReason === FinishReason::Length) {
            return null;
        }

        $title = trim($response->text, " \t\n\r\0\x0B\"'.");

        return $title === '' ? null : Str::limit($title, WhatsappDraftConversation::MAX_TITLE_LENGTH);
    }

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            "{$this->user->name} asked an AI to write a WhatsApp message. Name that conversation from their request, as a chat app names a conversation:",
            '- 2 to 6 words, in the language of the request, about what the message is for.',
            '- Output only the title: no quotes, no final period, no emoji.',
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
}
