<?php

namespace App\Livewire\Invoices;

use App\Models\Module;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * The workspaces of the invoices screens: the user's workspaces with the
 * `invoices` module, which the forms offer when creating and the lists mark
 * each record with. A user with the module in a single workspace sees none
 * of it.
 *
 * @property-read array<string, string> $workspaceOptions
 */
trait InteractsWithInvoiceWorkspaces
{
    /**
     * The user's workspaces with the `invoices` module, by name: friendly id
     * => name.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function workspaceOptions(): array
    {
        return $this->actor()->membershipsWithModule(Module::INVOICES)
            ->mapWithKeys(fn (WorkspaceMembership $membership): array => [$membership->workspace->friendly_id => $membership->workspace->name])
            ->all();
    }

    /**
     * Whether the workspaces show: only for a user with the module in more
     * than one.
     */
    public function showsWorkspaces(): bool
    {
        return count($this->workspaceOptions) > 1;
    }

    /**
     * The friendly id of the user's default workspace for invoices, '' when
     * they have none.
     */
    private function defaultWorkspaceId(): string
    {
        return $this->actor()->defaultWorkspaceFor(Module::INVOICES)?->friendly_id ?? '';
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
