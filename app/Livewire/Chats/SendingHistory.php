<?php

namespace App\Livewire\Chats;

use App\Models\ChatScheduledDelivery;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every sending of the messages the user scheduled on the chat screen,
 * newest first, 20 per page: when, to which conversation (the channel's
 * mark), what went (as the AI wrote it, when it did) and whether it was
 * sent, with why it failed. Only the user's own.
 *
 * @property-read LengthAwarePaginator<int, ChatScheduledDelivery> $deliveries
 */
#[Layout('layouts::app-shell')]
class SendingHistory extends Component
{
    use WithPagination;

    public const int PER_PAGE = 20;

    public const int EXCERPT_LENGTH = 80;

    /**
     * @return LengthAwarePaginator<int, ChatScheduledDelivery>
     */
    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        return ChatScheduledDelivery::query()
            ->where('user_id', $this->user()->id)
            ->with(['conversation.provider', 'scheduledMessage'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    public function render(): View
    {
        return view('livewire.chats.sending-history')->title(__('Sending history'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
