<?php

namespace App\Livewire\Chats;

use App\Actions\Chats\CancelChatScheduledMessage;
use App\Models\ChatConversation;
use App\Models\ChatScheduledMessage;
use App\Models\Module;
use App\Models\User;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The messages the user scheduled on the chat screen, of every channel
 * switched on for them, the soonest first: to which conversation, when
 * next, whether it repeats, the text or what the AI is asked to write,
 * each opening its conversation or cancelled here. Scheduled in a
 * conversation (ChatsPage); the ones of the WhatsApp screen stay on its
 * own list, linked from here.
 *
 * @property-read Collection<int, ChatScheduledMessage> $scheduled
 */
#[Layout('layouts::app-shell')]
class ScheduledMessages extends Component
{
    public const int SHOWN = 200;

    public ?string $notice = null;

    public ?string $error = null;

    /**
     * @return Collection<int, ChatScheduledMessage>
     */
    #[Computed]
    public function scheduled(): Collection
    {
        $providerIds = app(ChatProviderRegistry::class)->providersFor($this->user())->modelKeys();

        return ChatScheduledMessage::query()
            ->where('user_id', $this->user()->id)
            ->pending()
            ->whereHas('conversation', fn (Builder $query) => $query->whereIn('chat_provider_id', $providerIds))
            ->with(['conversation.provider', 'recurrence.frequency'])
            ->soonestFirst()
            ->limit(self::SHOWN)
            ->get();
    }

    public function cancel(string $scheduledId, CancelChatScheduledMessage $cancel): void
    {
        $this->reset('notice', 'error');

        $scheduled = ChatScheduledMessage::query()->where('user_id', $this->user()->id)->byFriendlyId($scheduledId)->firstOrFail();

        try {
            $cancel($this->user(), $scheduled);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->notice = __('Scheduled message cancelled.');
        unset($this->scheduled);
    }

    public function conversationUrl(ChatConversation $conversation): string
    {
        return route('chats.chat', ['conversation' => $conversation->friendly_id]);
    }

    public function hasWhatsappScreen(): bool
    {
        return $this->user()->can('access-module', Module::WHATSAPP);
    }

    public function render(): View
    {
        return view('livewire.chats.scheduled-messages')->title(__('Scheduled messages'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
