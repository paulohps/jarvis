<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A messaging service the chat layer talks to (Key Concept "Chat channel";
 * "canal" on the screens): WhatsApp, Slack… Each has a driver
 * (App\Services\Chats\ChatProviderDriver, found by slug in
 * ChatProviderRegistry) that says what it supports. The super admin
 * switches a provider on per workspace (`chat_provider_workspace`) and per
 * member (`chat_provider_workspace_user`): a user may connect and read a
 * provider when it is on for them in a workspace where they have the
 * `chats` module.
 *
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['slug', 'name', 'is_active', 'sort_order'])]
class ChatProvider extends Lookup
{
    public const string WHATSAPP = 'whatsapp';

    public const string SLACK = 'slack';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The workspaces with this provider on.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class)
            ->withPivot('enabled_by_id')
            ->withTimestamps();
    }
}
