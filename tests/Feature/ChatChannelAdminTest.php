<?php

use App\Actions\Workspaces\SetMemberChatProviders;
use App\Actions\Workspaces\SetWorkspaceChatProviders;
use App\Livewire\Admin\Workspaces\WorkspaceDetail;
use App\Models\ChatProvider;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->admin = User::factory()->superAdmin()->create();
    $this->workspace = Workspace::factory()->create();
    $this->member = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    $this->membership = $this->member->memberships()->sole();
});

test('a member uses a channel only when it is on for the workspace and for them', function () {
    // A provider switched off in the lookup is off for everyone, the super admin included.
    ChatProvider::query()->slug(ChatProvider::WHATSAPP)->update(['is_active' => false]);

    expect($this->member->hasChatProvider(ChatProvider::SLACK))->toBeFalse()
        ->and($this->admin->hasChatProvider(ChatProvider::SLACK))->toBeTrue()
        ->and($this->admin->hasChatProvider(ChatProvider::WHATSAPP))->toBeFalse();

    $component = Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $this->workspace])
        ->assertSeeHtml('data-workspace-channel="slack"')
        ->assertSeeHtml('data-member-channel="'.$this->member->friendly_id.'.slack"')
        ->set('workspaceChannels.slack', true);

    expect($this->workspace->fresh()->hasChatProvider(ChatProvider::SLACK))->toBeTrue()
        ->and($this->member->fresh()->hasChatProvider(ChatProvider::SLACK))->toBeFalse();

    $component->set('memberChannels.'.$this->membership->id.'.slack', true);

    expect($this->member->fresh()->hasChatProvider(ChatProvider::SLACK))->toBeTrue();

    $component->set('workspaceChannels.slack', false);

    expect($this->member->fresh()->hasChatProvider(ChatProvider::SLACK))->toBeFalse()
        ->and($this->membership->fresh()->chatProviders()->count())->toBe(1);

    // Without the chats module the switches are gone and the channel is off whatever the rows.
    $component->set('workspaceChannels.slack', true)
        ->set('access.'.$this->membership->id.'.'.Module::CHATS, false)
        ->assertDontSeeHtml('data-member-channel="'.$this->member->friendly_id.'.slack"');

    expect($this->member->fresh()->hasChatProvider(ChatProvider::SLACK))->toBeFalse();
});

test('an inactive provider is never on', function () {
    ChatProvider::query()->slug(ChatProvider::SLACK)->update(['is_active' => false]);
    $this->workspace->chatProviders()->sync([ChatProvider::idFor(ChatProvider::SLACK) => ['enabled_by_id' => $this->admin->id]]);
    $this->membership->chatProviders()->sync([ChatProvider::idFor(ChatProvider::SLACK) => ['enabled_by_id' => $this->admin->id]]);

    expect($this->member->fresh()->hasChatProvider(ChatProvider::SLACK))->toBeFalse()
        ->and($this->admin->hasChatProvider(ChatProvider::SLACK))->toBeFalse();

    Livewire::actingAs($this->admin)
        ->test(WorkspaceDetail::class, ['workspace' => $this->workspace])
        ->assertDontSeeHtml('data-workspace-channel="slack"');
});

test('only the super admin switches channels', function () {
    expect(fn () => app(SetWorkspaceChatProviders::class)($this->member, $this->workspace, ['slack']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(SetMemberChatProviders::class)($this->admin, $this->membership, ['whatsapp-x']))
        ->toThrow(ValidationException::class);
});
