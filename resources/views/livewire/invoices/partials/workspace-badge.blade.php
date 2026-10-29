{{--
    The workspace of a payer, issuer, recurrence or invoice, for a user with
    invoices in several workspaces: a dot in the color they see it in, and
    its name. Expects $workspace (App\Models\Workspace).
--}}
@php
    $workspaceColor = \App\Models\Workspace::hexOf(auth()->user()->colorOf($workspace));
@endphp
<span class="inline-flex min-w-0 items-center gap-1.5 text-[0.8125rem] text-secondary-label" data-invoice-workspace="{{ $workspace->friendly_id }}">
    <span aria-hidden="true" class="size-2 shrink-0 rounded-full" style="background-color: {{ $workspaceColor }}"></span>
    <span class="truncate">{{ $workspace->name }}</span>
</span>
