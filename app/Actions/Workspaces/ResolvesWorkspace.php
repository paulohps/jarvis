<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * For the Actions on shared data (shopping, agenda, invoices): where a new
 * record goes and whether the actor may touch an existing one.
 *
 * A new record goes to the `workspace` of the input (a friendly id) — one
 * of the actor's workspaces with the module — or, left out, to their
 * default for the module (User::defaultWorkspaceFor), so a client that
 * knows nothing of workspaces keeps working.
 */
trait ResolvesWorkspace
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the workspace is not one of the actor's with the module, or they have none
     */
    private function workspaceFor(User $actor, array $input, string $moduleSlug): Workspace
    {
        $friendlyId = $input['workspace'] ?? null;

        if ($friendlyId === null || $friendlyId === '') {
            return $actor->defaultWorkspaceFor($moduleSlug) ?? throw ValidationException::withMessages([
                'workspace' => __('You are in no workspace with this module.'),
            ]);
        }

        $membership = is_string($friendlyId)
            ? $actor->membershipsWithModule($moduleSlug)->first(fn ($membership): bool => $membership->workspace->friendly_id === $friendlyId)
            : null;

        return $membership?->workspace ?? throw ValidationException::withMessages([
            'workspace' => __('The selected workspace is invalid.'),
        ]);
    }

    /**
     * Refuses, as not found, a record of a workspace the actor does not see:
     * any model with `isVisibleTo(User)` — one using BelongsToWorkspace, or a
     * child that asks its parent (e.g. ReminderOccurrence).
     *
     *
     * @throws ModelNotFoundException
     */
    private function authorizeWorkspaceRecord(User $actor, Model $record): void
    {
        if (! method_exists($record, 'isVisibleTo') || ! $record->isVisibleTo($actor)) {
            throw (new ModelNotFoundException)->setModel($record::class, [$record->getKey()]);
        }
    }
}
