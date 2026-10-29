<?php

namespace App\Livewire\Whatsapp;

use App\Models\User;
use App\Models\WhatsappDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every WhatsApp delivery from the user's own accounts (US-7.5), newest
 * first, 20 per page: when, from which account, to which target, the
 * beginning of the text and the status (with the error of a failed one).
 * Scheduled, "Send now", notification and voice deliveries alike; nobody
 * else's, the super admin's included.
 *
 * @property-read LengthAwarePaginator<int, WhatsappDelivery> $deliveries
 */
#[Layout('layouts::app-shell')]
class DeliveryHistory extends Component
{
    use WithPagination;

    public const int PER_PAGE = 20;

    /**
     * How much of the text a row shows.
     */
    public const int EXCERPT_LENGTH = 60;

    /**
     * @return LengthAwarePaginator<int, WhatsappDelivery>
     */
    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        return WhatsappDelivery::query()
            ->whereIn('whatsapp_account_id', $this->user()->whatsappAccounts()->select('id'))
            ->with(['account', 'target', 'status', 'source'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.delivery-history')->title(__('WhatsApp history'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
