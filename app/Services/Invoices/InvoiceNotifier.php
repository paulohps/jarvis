<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Module;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\InvoiceFailed;
use App\Services\Notifications\Types\InvoiceNotification;
use App\Services\Notifications\Types\InvoicePendingReview;
use App\Services\Notifications\Types\InvoiceSent;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tells the workspace what happened to an invoice (US-5.4): the invoices
 * are shared by the members of their workspace, so the audience is every
 * active member of the invoice's workspace with the `invoices` module there
 * (a super admin member included). Nobody of another workspace is told.
 * The notification center drops whoever opted out of the channel.
 */
final readonly class InvoiceNotifier
{
    public function __construct(private NotificationCenter $center) {}

    public function sent(Invoice $invoice): void
    {
        $this->notify(new InvoiceSent($invoice));
    }

    public function failed(Invoice $invoice): void
    {
        $this->notify(new InvoiceFailed($invoice));
    }

    public function pendingReview(Invoice $invoice): void
    {
        $this->notify(new InvoicePendingReview($invoice));
    }

    /**
     * The active members of the invoice's workspace with the `invoices`
     * module there.
     *
     * @return Collection<int, User>
     */
    public static function audience(Invoice $invoice): Collection
    {
        return $invoice->workspace
            ->membersWithModule(Module::INVOICES)
            ->whereNull('users.deactivated_at')
            ->get();
    }

    private function notify(InvoiceNotification $notification): void
    {
        $this->center->dispatch($notification, self::audience($notification->invoice));
    }
}
