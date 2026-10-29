<?php

namespace App\Console\Commands;

use App\Actions\Chats\SendChatScheduledMessage;
use App\Models\ChatScheduledMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sends the scheduled messages of the chat screen that are due, run every
 * minute (SendChatScheduledMessage), each on its own so one failing does
 * not hold the others.
 */
#[Signature('chats:send-scheduled')]
#[Description('Send the scheduled chat messages that are due')]
class SendDueChatScheduledMessages extends Command
{
    public function handle(SendChatScheduledMessage $send): int
    {
        $sent = 0;

        ChatScheduledMessage::query()
            ->due()
            ->with(['user', 'conversation'])
            ->orderBy('send_at')
            ->orderBy('id')
            ->each(function (ChatScheduledMessage $scheduled) use ($send, &$sent): void {
                if ($send($scheduled)->sent_at !== null) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} scheduled chat message(s).");

        return self::SUCCESS;
    }
}
