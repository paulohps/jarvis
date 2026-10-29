<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Storage::fake('public');

    $this->user = User::factory()->create([
        'name' => 'Ana',
        'password' => 'old-password',
        'locale_id' => Locale::idFor('en'),
        'timezone' => 'UTC',
    ]);
});

test('guests cannot open the profile', function () {
    $this->get(route('profile'))->assertRedirect(route('login'));
});

test('the profile opens on the profile tab from the user menu', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSee(route('profile'), false);

    $this->actingAs($this->user)
        ->get(route('profile'))
        ->assertOk()
        ->assertSeeLivewire(ProfilePage::class)
        ->assertSee('data-profile-tab="profile"', false)
        ->assertSee(['Profile', 'Preferences', 'Notifications', 'Devices', 'Security']);
});

test('each tab loads through livewire without leaving the page', function () {
    $page = Livewire::actingAs($this->user)->test(ProfilePage::class);

    $page->set('tab', 'preferences')
        ->assertNoRedirect()
        ->assertSee('data-preferences-form', false)
        ->assertSee('America/Sao Paulo')
        ->set('tab', 'security')
        ->assertNoRedirect()
        ->assertSee('data-password-form', false)
        ->assertSee('data-connected-accounts', false)
        ->set('tab', 'profile')
        ->assertSee('data-profile-form', false)
        ->set('tab', 'not-a-tab')
        ->assertSet('tab', 'profile');
});

test('the tab is read from the url', function () {
    Livewire::withQueryParams(['tab' => 'security'])
        ->actingAs($this->user)
        ->test(ProfilePage::class)
        ->assertSet('tab', 'security')
        ->assertSee('data-password-form', false);

    Livewire::withQueryParams(['tab' => 'anything'])
        ->actingAs($this->user)
        ->test(ProfilePage::class)
        ->assertSet('tab', 'profile');
});

test('saving the profile confirms with a toast', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('name', 'Ana Clara')
        ->set('avatar', UploadedFile::fake()->image('avatar.webp'))
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast')
        ->assertSet('avatar', null)
        ->assertSee('data-profile-avatar', false);

    expect($this->user->fresh())
        ->name->toBe('Ana Clara')
        ->avatar_path->toStartWith('avatars/');
});

test('an invalid avatar is shown as a field error', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('avatar', UploadedFile::fake()->image('avatar.jpg')->size(3000))
        ->call('saveProfile')
        ->assertHasErrors(['avatar'])
        ->assertNotDispatched('ts-ui:toast');

    expect($this->user->fresh()->avatar_path)->toBeNull();
});

test('a new language reloads the profile in it', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class, ['tab' => 'preferences'])
        ->set('tab', 'preferences')
        ->set('locale', 'pt_BR')
        ->set('timezone', 'America/Sao_Paulo')
        ->call('savePreferences')
        ->assertHasNoErrors()
        ->assertRedirect(route('profile', ['tab' => 'preferences']));

    expect($this->user->fresh())
        ->locale->slug->toBe('pt_BR')
        ->timezone->toBe('America/Sao_Paulo');

    $this->actingAs($this->user->fresh())
        ->get(route('profile', ['tab' => 'preferences']))
        ->assertSee('Preferências');
});

test('a new timezone is confirmed with a toast', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'preferences')
        ->set('timezone', 'Europe/Lisbon')
        ->call('savePreferences')
        ->assertNoRedirect()
        ->assertDispatched('ts-ui:toast');

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('timezone', 'Nowhere/Land')
        ->call('savePreferences')
        ->assertHasErrors(['timezone']);

    expect($this->user->fresh()->timezone)->toBe('Europe/Lisbon');
});

test('changing the password asks for the current one', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'security')
        ->assertSee('Current password')
        ->set('current_password', 'wrong')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('savePassword')
        ->assertHasErrors(['current_password'])
        ->set('current_password', 'old-password')
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast')
        ->assertSet('password', '');

    expect(Hash::check('new-password', $this->user->fresh()->password))->toBeTrue();
});

test('a google only user sets a first password without the current one', function () {
    $user = User::factory()->googleOnly()->create();

    Livewire::actingAs($user)
        ->test(ProfilePage::class)
        ->set('tab', 'security')
        ->assertDontSee('Current password')
        ->assertSee('Set a password before disconnecting an account')
        ->assertDontSee('disconnectSocialAccount', false)
        ->set('password', 'first-password')
        ->set('password_confirmation', 'first-password')
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast')
        ->assertSee('disconnectSocialAccount', false);
});

test('connected accounts can be disconnected only with a password', function () {
    $googleOnly = User::factory()->googleOnly()->create();
    $account = $googleOnly->socialAccounts()->sole();

    Livewire::actingAs($googleOnly)
        ->test(ProfilePage::class)
        ->set('tab', 'security')
        ->call('disconnectSocialAccount', $account->id)
        ->assertHasErrors(['social_account'])
        ->assertSee('Set a password first.');

    expect($account->fresh())->not->toBeNull();

    $linked = SocialAccount::factory()->for($this->user)->create(['email' => 'ana@gmail.com']);

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'security')
        ->assertSee('ana@gmail.com')
        ->call('disconnectSocialAccount', $linked->id)
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast')
        ->assertDontSee('ana@gmail.com')
        ->assertSee('No connected accounts');

    expect($linked->fresh())->toBeNull();
});

test('another users social account cannot be disconnected', function () {
    $someoneElses = SocialAccount::factory()->create();

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->call('disconnectSocialAccount', $someoneElses->id)
        ->assertNotFound();

    expect($someoneElses->fresh())->not->toBeNull();
});

test('the profile lists the user workspaces with the default one', function () {
    $costa = Workspace::factory()->create(['name' => 'Costa family']);
    $user = User::factory()->withModules([])->inWorkspace($costa)->create();

    Livewire::actingAs($user)
        ->test(ProfilePage::class)
        ->assertSee('data-profile-workspace="'.$costa->friendly_id.'"', false)
        ->assertSee('data-profile-workspace="'.WorkspaceFactory::household()->friendly_id.'"', false)
        ->assertSeeInOrder(['Costa family', 'Make default', WorkspaceFactory::HOUSEHOLD, __('Default')]);
});

test('the user picks the color they see a workspace in and goes back to its own', function () {
    $household = WorkspaceFactory::household();
    $user = User::factory()->withModules([])->create();
    $membership = $user->membershipOf($household);

    $component = Livewire::actingAs($user)
        ->test(ProfilePage::class)
        ->assertSet("workspaceColors.{$membership->id}", '')
        ->set("workspaceColors.{$membership->id}", 'orange')
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast');

    expect($user->fresh()->colorOf($household))->toBe('orange')
        ->and($household->fresh()->color)->toBe('blue');

    $component->set("workspaceColors.{$membership->id}", '')->assertHasNoErrors();

    expect($user->fresh()->colorOf($household))->toBe('blue');

    $component->set("workspaceColors.{$membership->id}", 'chartreuse')->assertHasErrors(['color']);

    expect($user->fresh()->colorOf($household))->toBe('blue');
});

test('the user makes another workspace their default', function () {
    $costa = Workspace::factory()->create();
    $user = User::factory()->withModules([])->inWorkspace($costa)->create();
    $membership = $user->membershipOf($costa);

    Livewire::actingAs($user)
        ->test(ProfilePage::class)
        ->call('makeDefaultWorkspace', $membership->id)
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast');

    expect($membership->fresh()->is_default)->toBeTrue()
        ->and($user->fresh()->membershipOf(WorkspaceFactory::household())->is_default)->toBeFalse();
});

test('a user cannot change someone else membership from the profile', function () {
    $other = User::factory()->withModules([])->create();
    $membership = $other->membershipOf(WorkspaceFactory::household());

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->call('makeDefaultWorkspace', $membership->id)
        ->assertNotFound();

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set("workspaceColors.{$membership->id}", 'red')
        ->assertNotFound();

    expect($membership->fresh()->color)->toBeNull();
});
