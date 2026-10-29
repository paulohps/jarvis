<?php

namespace App\Livewire\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Payer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every issued invoice (US-6.8), newest first, 20 per page: number, payer,
 * date, due date, currency, amount and status, each opening the invoice,
 * with the download of its PDF. The filters — status, payer and month of
 * the invoice date (`YYYY-MM`) — combine and are kept in the URL; a value
 * that is not one of the options is ignored.
 *
 * Only the invoices of the reader's workspaces with the module, each marked
 * with its workspace when they have several (numbers are counted per
 * workspace, so two may share one).
 *
 * @property-read LengthAwarePaginator<int, Invoice> $invoices
 * @property-read Collection<int, Payer> $payers
 * @property-read Collection<int, InvoiceStatus> $statuses
 */
#[Layout('layouts::app-shell')]
class InvoiceHistory extends Component
{
    use InteractsWithInvoiceWorkspaces;
    use WithPagination;

    public const int PER_PAGE = 20;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $payer = '';

    #[Url(except: '')]
    public string $month = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'payer', 'month'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'payer', 'month');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        $status = $this->statuses->firstWhere('slug', $this->status);
        $payer = $this->payers->firstWhere('friendly_id', $this->payer);
        $month = $this->selectedMonth();

        return Invoice::query()
            ->visibleTo($this->actor())
            ->with(['status', 'currency', 'workspace'])
            ->when($status !== null, fn (Builder $query) => $query->where('invoice_status_id', $status?->id))
            ->when($payer !== null, fn (Builder $query) => $query->where('payer_id', $payer?->id))
            ->when($month !== null, fn (Builder $query) => $query->whereBetween('issue_date', [
                $month?->startOfMonth()->toDateString(),
                $month?->endOfMonth()->toDateString(),
            ]))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * The payers of the reader's workspaces that were billed, deleted ones
     * included.
     *
     * @return Collection<int, Payer>
     */
    #[Computed]
    public function payers(): Collection
    {
        return Payer::query()->visibleTo($this->actor())->withTrashed()->whereHas('invoices')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, InvoiceStatus>
     */
    #[Computed]
    public function statuses(): Collection
    {
        return InvoiceStatus::query()->orderBy('id')->get();
    }

    public function hasFilters(): bool
    {
        return $this->status !== '' || $this->payer !== '' || $this->month !== '';
    }

    public function render(): View
    {
        return view('livewire.invoices.invoice-history')->title(__('Invoice history'));
    }

    private function selectedMonth(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: null;
    }
}
