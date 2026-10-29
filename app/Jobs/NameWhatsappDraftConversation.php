<?php

namespace App\Jobs;

use App\Ai\Agents\WhatsappDraftTitleAgent;
use App\Models\WhatsappDraftConversation;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Names a new conversation with the AI from its first request
 * (WhatsappDraftTitleAgent). Run after the response (dispatchAfterResponse):
 * until then, and when the AI fails, the conversation keeps the start of
 * the request as its title.
 */
class NameWhatsappDraftConversation
{
    use Dispatchable;

    public function __construct(public WhatsappDraftConversation $conversation) {}

    public function handle(): void
    {
        try {
            $title = WhatsappDraftTitleAgent::forUser($this->conversation->user)->title($this->conversation);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($title !== null) {
            $this->conversation->update(['title' => $title]);
        }
    }
}
