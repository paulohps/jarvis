<?php

use App\Http\Controllers\Chats\SlackConnectCallbackController;
use App\Http\Controllers\Chats\SlackConnectRedirectController;
use App\Jobs\SyncSlackAccount;
use App\Livewire\Chats\ChatChannelList;
use App\Models\ChatProvider;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Slack\SlackChatProvider;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    config(['services.slack.client_id' => 'client-id', 'services.slack.client_secret' => 'client-secret']);
    Queue::fake();
    FakeSlackApi::install();

    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    $this->enableSlack = function (User $user): void {
        $slack = ChatProvider::idFor(ChatProvider::SLACK);
        $this->workspace->chatProviders()->syncWithoutDetaching([$slack => ['enabled_by_id' => $user->id]]);
        $membership = $user->memberships()->where('workspace_id', $this->workspace->id)->sole();
        $membership->chatProviders()->syncWithoutDetaching([$slack => ['enabled_by_id' => $user->id]]);
        $user->forgetMemberships();
    };
    ($this->enableSlack)($this->user);
});

test('the redirect sends the user to slack with the user scopes and a state', function () {
    $response = $this->actingAs($this->user)->get(route('chats.slack.redirect'));

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://slack.com/oauth/v2/authorize?')
        ->and($query['client_id'])->toBe('client-id')
        ->and($query['user_scope'])->toContain('chat:write', 'im:history')
        ->and($query['scope'])->toBe('')
        ->and($query['redirect_uri'])->toBe(route('chats.slack.callback'))
        ->and($query['state'])->toBe(session(SlackConnectRedirectController::SESSION_KEY));
});

test('the callback keeps the workspace with its token and queues the sync', function () {
    $this->actingAs($this->user)->withSession([SlackConnectRedirectController::SESSION_KEY => 'the-state'])
        ->get(route('chats.slack.callback', ['code' => 'the-code', 'state' => 'the-state']))
        ->assertRedirect(route('chats.index'))
        ->assertSessionHas(SlackConnectCallbackController::STATUS_KEY);

    $account = SlackAccount::query()->where('user_id', $this->user->id)->sole();
    expect($account->team_id)->toBe(FakeSlackApi::TEAM_ID)
        ->and($account->team_name)->toBe('Acme')
        ->and($account->team_domain)->toBe('acme')
        ->and($account->slack_user_id)->toBe(FakeSlackApi::USER_ID)
        ->and($account->access_token)->toBe(FakeSlackApi::TOKEN)
        ->and($account->isUsable())->toBeTrue();

    expect(FakeSlackApi::called('oauth.v2.access', ['code' => 'the-code', 'client_secret' => 'client-secret']))->toBeTrue();
    Queue::assertPushed(SyncSlackAccount::class, fn (SyncSlackAccount $job): bool => $job->account->is($account));

    // Connecting the same workspace again replaces the token and clears a failure.
    $account->forceFill(['failed_at' => now(), 'last_error' => 'token_revoked'])->save();
    FakeSlackApi::install(['oauth.v2.access' => ['ok' => true, 'authed_user' => ['id' => FakeSlackApi::USER_ID, 'scope' => implode(',', SlackChatProvider::USER_SCOPES), 'access_token' => 'xoxp-new'], 'team' => ['id' => FakeSlackApi::TEAM_ID, 'name' => 'Acme']]]);

    $this->actingAs($this->user)->withSession([SlackConnectRedirectController::SESSION_KEY => 's2'])
        ->get(route('chats.slack.callback', ['code' => 'c2', 'state' => 's2']))
        ->assertRedirect(route('chats.index'));

    expect(SlackAccount::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('xoxp-new')
        ->and($account->fresh()->isUsable())->toBeTrue();
});

test('a wrong state, a denied grant or missing scopes connect nothing', function () {
    $this->actingAs($this->user)->withSession([SlackConnectRedirectController::SESSION_KEY => 'the-state'])
        ->get(route('chats.slack.callback', ['code' => 'c', 'state' => 'other']))
        ->assertRedirect(route('chats.index'))
        ->assertSessionHas(SlackConnectCallbackController::ERROR_KEY);

    $this->actingAs($this->user)->withSession([SlackConnectRedirectController::SESSION_KEY => 's'])
        ->get(route('chats.slack.callback', ['error' => 'access_denied', 'state' => 's']))
        ->assertSessionHas(SlackConnectCallbackController::ERROR_KEY);

    FakeSlackApi::install(['oauth.v2.access' => ['ok' => true, 'authed_user' => ['id' => FakeSlackApi::USER_ID, 'scope' => 'chat:write', 'access_token' => 'xoxp-x'], 'team' => ['id' => FakeSlackApi::TEAM_ID, 'name' => 'Acme']]]);
    $this->actingAs($this->user)->withSession([SlackConnectRedirectController::SESSION_KEY => 's'])
        ->get(route('chats.slack.callback', ['code' => 'c', 'state' => 's']))
        ->assertSessionHas(SlackConnectCallbackController::ERROR_KEY);

    expect(SlackAccount::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a slack user already connected by someone else is refused', function () {
    $other = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    SlackAccount::factory()->for($other)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID]);

    $this->actingAs($this->user)->withSession([SlackConnectRedirectController::SESSION_KEY => 's'])
        ->get(route('chats.slack.callback', ['code' => 'c', 'state' => 's']))
        ->assertSessionHas(SlackConnectCallbackController::ERROR_KEY, __('This Slack user is already connected by someone else.'));

    expect(SlackAccount::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('only users with the chats module and slack switched on may connect', function () {
    $noChannel = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    $this->actingAs($noChannel)->get(route('chats.slack.redirect'))->assertForbidden();

    $noModule = User::factory()->inWorkspace($this->workspace, [Module::SHOPPING])->create();
    $this->actingAs($noModule)->get(route('chats.slack.redirect'))->assertForbidden();
    $this->actingAs($noModule)->get(route('chats.index'))->assertForbidden();
});

test('the channels page lists the accounts, syncs and disconnects them', function () {
    $account = SlackAccount::factory()->for($this->user)->create(['team_name' => 'Acme', 'team_domain' => 'acme']);

    $this->actingAs($this->user)->get(route('chats.index'))
        ->assertOk()
        ->assertSeeLivewire(ChatChannelList::class)
        ->assertSee('Acme')
        ->assertSee("data-chat-account=\"{$account->friendly_id}\"", false)
        ->assertSee(route('chats.slack.redirect'), false);

    Livewire::actingAs($this->user)
        ->test(ChatChannelList::class)
        ->call('resyncSlack', $account->friendly_id)
        ->assertSet('status', __('The conversations are being synced. They show up on the chat screen as they come.'));
    Queue::assertPushed(SyncSlackAccount::class);

    Livewire::actingAs($this->user)
        ->test(ChatChannelList::class)
        ->call('disconnectSlack', $account->friendly_id)
        ->assertSet('status', __(':workspace was disconnected.', ['workspace' => 'Acme']));

    expect(SlackAccount::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(FakeSlackApi::called('auth.revoke'))->toBeTrue();
});

test('someone else cannot disconnect the account', function () {
    $other = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    ($this->enableSlack)($other);
    $account = SlackAccount::factory()->for($this->user)->create();

    Livewire::actingAs($other)
        ->test(ChatChannelList::class)
        ->call('disconnectSlack', $account->friendly_id)
        ->assertNotFound();

    expect(SlackAccount::query()->whereKey($account->id)->exists())->toBeTrue();
});
