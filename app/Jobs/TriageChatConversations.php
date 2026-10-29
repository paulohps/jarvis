<?php

namespace App\Jobs;

use App\Actions\Chats\ApplyChatConversationsBulkAction;
use App\Ai\Agents\ChatTriageAgent;
use App\Events\ChatConversationsTriaged;
use App\Events\ChatConversationUpdated;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * The AI triages the conversations RequestChatTriage asked for, on the
 * `chats` queue (ChatTriageAgent): each one it answered for gets its
 * urgency and summary, and the message it read up to; every one stops
 * waiting, and the owner's chat screen reloads the list
 * (ChatConversationsTriaged). Only the `ai_*` columns are written (on a
 * WhatsApp chat's target too, which the entry copies), never the user's
 * `priority`; a conversation read meanwhile gets nothing.
 *
 * A single attempt, with more time than the AI's own timeout; when it
 * fails, the conversations stop waiting and the screen says so.
 */
#[Tries(1)]
#[Timeout(120)]
#[DeleteWhenMissingModels]
class TriageChatConversations implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $conversationIds
     */
    public function __construct(
        public User $user,
        public array $conversationIds,
    ) {
        $this->onQueue(ChatConversationUpdated::QUEUE);
    }

    public function handle(ChatProviderRegistry $registry): void
    {
        $conversations = $this->conversations();

        // What the AI reads goes up to the latest message now.
        $readThrough = $conversations->mapWithKeys(fn (ChatConversation $conversation): array => [$conversation->id => $conversation->last_message_at]);

        $triaged = $conversations->isEmpty() ? [] : ChatTriageAgent::forUser($this->user)->triage($conversations);

        ApplyChatConversationsBulkAction::quietly(function () use ($conversations, $registry, $triaged, $readThrough): void {
            foreach ($conversations->fresh() as $conversation) {
                // A conversation read while the AI was at it keeps nothing of it.
                $answer = $conversation->isUnread() ? ($triaged[$conversation->friendly_id] ?? null) : null;

                $changes = $answer === null ? ['ai_triage_requested_at' => null] : [
                    'ai_urgency' => $answer['urgency'],
                    'ai_summary' => $answer['summary'] !== '' ? $answer['summary'] : null,
                    'ai_triaged_at' => now(),
                    'ai_triaged_through' => $readThrough[$conversation->id],
                    'ai_triage_requested_at' => null,
                ];

                $registry->for($conversation)->entryChanged($conversation, $changes);
                $conversation->forceFill($changes)->save();
            }
        });

        ChatConversationsTriaged::dispatch($this->user->id);
    }

    public function failed(Throwable $exception): void
    {
        $registry = app(ChatProviderRegistry::class);

        foreach ($this->conversations() as $conversation) {
            rescue(fn () => $registry->for($conversation)->entryChanged($conversation, ['ai_triage_requested_at' => null]));
            $conversation->forceFill(['ai_triage_requested_at' => null])->save();
        }

        ChatConversationsTriaged::dispatch($this->user->id, failed: true);
    }

    /**
     * @return Collection<int, ChatConversation>
     */
    private function conversations(): Collection
    {
        return ChatConversation::query()->ofUser($this->user)->whereKey($this->conversationIds)->with('provider')->get();
    }
}
