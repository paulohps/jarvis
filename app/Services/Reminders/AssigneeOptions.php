<?php

namespace App\Services\Reminders;

use App\Models\Module;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Who may be made responsible for a reminder, for the apps' forms: the
 * `Reminder::assignees()` of a workspace as `{friendly_id, name}`. A
 * reminder takes its assignee from its own workspace, so the lists come
 * per workspace — the reader's default one (where a new reminder goes when
 * none is picked) and each of theirs with the `reminders` module.
 */
final readonly class AssigneeOptions
{
    /**
     * @return list<array{friendly_id: string, name: string}>
     */
    public function of(?Workspace $workspace): array
    {
        if ($workspace === null) {
            return [];
        }

        return Reminder::assignees($workspace)
            ->map(fn (User $user): array => ['friendly_id' => $user->friendly_id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * The assignees of the reader's default workspace for reminders.
     *
     * @return list<array{friendly_id: string, name: string}>
     */
    public function ofDefault(User $reader): array
    {
        return $this->of($reader->defaultWorkspaceFor(Module::REMINDERS));
    }

    /**
     * The assignees of each of the reader's workspaces with the `reminders`
     * module, by the workspace's friendly id.
     *
     * @return array<string, list<array{friendly_id: string, name: string}>>
     */
    public function byWorkspace(User $reader): array
    {
        return $reader->membershipsWithModule(Module::REMINDERS)
            ->mapWithKeys(fn (WorkspaceMembership $membership): array => [
                $membership->workspace->friendly_id => $this->of($membership->workspace),
            ])
            ->all();
    }
}
