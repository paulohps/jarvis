<?php

namespace App\Jobs;

use App\Actions\Slack\HandleSlackEvent;
use App\Events\ChatConversationUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * One Events API callback, handled off the request (Slack wants its 200
 * within three seconds) on the `chats` queue: HandleSlackEvent applies it
 * to the accounts of its workspace.
 */
#[Tries(3)]
#[Backoff(10, 60)]
#[Timeout(60)]
class ProcessSlackEvent implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $this->onQueue(ChatConversationUpdated::QUEUE);
    }

    public function handle(HandleSlackEvent $handleEvent): void
    {
        $handleEvent($this->payload);
    }
}
