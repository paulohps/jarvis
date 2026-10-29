<?php

namespace App\Jobs;

use App\Actions\Chats\SendChatScheduledMessage;
use App\Events\ChatConversationUpdated;
use App\Models\ChatScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Sends one occurrence of a scheduled message of the chat screen
 * (SendChatScheduledMessage), on the `chats` queue: the AI may take a while
 * to write it. A single attempt: what fails is kept as a failed sending.
 */
#[Tries(1)]
#[Timeout(120)]
#[DeleteWhenMissingModels]
class SendChatScheduledSlot implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ChatScheduledMessage $scheduled,
        public CarbonImmutable $slot,
    ) {
        $this->onQueue(ChatConversationUpdated::QUEUE);
    }

    public function handle(SendChatScheduledMessage $send): void
    {
        $send($this->scheduled, $this->slot);
    }
}
