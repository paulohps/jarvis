<?php

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Events\UserDeactivated;
use App\Livewire\Admin\AdminHome;
use App\Livewire\Admin\Users\UserList;
use App\Livewire\Auth\Login;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->admin = User::factory()->superAdmin()->create(['name' => 'Paulo']);

    Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function () {
        Route::get('user-probe', fn () => ['ok' => true]);
    });
});

test('deactivating revokes all api tokens', function () {
    $user = User::factory()->create();
    $phoneToken = $user->createToken('iPhone')->plainTextToken;
    $user->createToken('Pixel');
    $otherToken = User::factory()->create()->createToken('iPad')->plainTextToken;

    $this->withToken($phoneToken)->getJson('/api/v1/user-probe')->assertOk();

    Livewire::actingAs($this->admin)
        ->test(UserList::class)
        ->call('deactivate', $user->id)
        ->assertHasNoErrors()
        ->assertSee("{$user->name} was deactivated.")
        ->assertSee("data-user=\"{$user->friendly_id}\"", false);

    expect($user->fresh()->isDeactivated())->toBeTrue()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(1);

    app('auth')->forgetGuards();

    $this->withToken($phoneToken)->getJson('/api/v1/user-probe')->assertUnauthorized();
    $this->withToken($otherToken)->getJson('/api/v1/user-probe')->assertOk();
});

test('deactivated user session is terminated', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'browser-of-the-user', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'browser-of-the-admin', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => now()->timestamp],
    ]);
    $rememberToken = $user->remember_token;

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    app(DeactivateUser::class)($this->admin, $user);

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['browser-of-the-admin'])
        ->and($user->fresh()->remember_token)->not->toBe($rememberToken);

    // The session the driver could not reach ends on the next request.
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('super admin cannot deactivate self', function () {
    Livewire::actingAs($this->admin)
        ->test(UserList::class)
        ->assertDontSee("deactivate({$this->admin->id})")
        ->call('deactivate', $this->admin->id)
        ->assertHasErrors('user');

    expect($this->admin->fresh()->isDeactivated())->toBeFalse();

    $member = User::factory()->create();

    expect(fn () => app(DeactivateUser::class)($member, User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeactivateUser::class)($this->admin, $this->admin))->toThrow(ValidationException::class);

    $this->actingAs($member)->get(route('admin.users.index'))->assertForbidden();
});

test('reactivated user can log in again', function () {
    $user = User::factory()->withModules([Module::SHOPPING])->create(['email' => 'ana@example.com']);

    app(DeactivateUser::class)($this->admin, $user);

    Livewire::test(Login::class)
        ->set('email', 'ana@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();

    Livewire::actingAs($this->admin)
        ->test(UserList::class)
        ->assertSee('data-user-status="deactivated"', false)
        ->call('reactivate', $user->id)
        ->assertHasNoErrors()
        ->assertSee("{$user->name} was reactivated.");

    expect($user->fresh()->isDeactivated())->toBeFalse()
        ->and($user->fresh()->hasModule(Module::SHOPPING))->toBeTrue();

    auth()->logout();

    Livewire::test(Login::class)
        ->set('email', 'ana@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);

    expect(fn () => app(ReactivateUser::class)($this->admin, $user->fresh()))->toThrow(ValidationException::class);
});

test('user deactivated event is dispatched', function () {
    Event::fake([UserDeactivated::class]);

    $user = User::factory()->create();

    app(DeactivateUser::class)($this->admin, $user);

    Event::assertDispatchedTimes(UserDeactivated::class, 1);
    Event::assertDispatched(UserDeactivated::class, fn (UserDeactivated $event): bool => $event->user->is($user));
});

test('the list shows name, e-mail, last access and workspaces', function () {
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(14, 30));

    $household = WorkspaceFactory::household();
    $costa = Workspace::factory()->create(['name' => 'Costa family', 'color' => 'green']);
    $ana = User::factory()->withModules([Module::SHOPPING, Module::INVOICES])->inWorkspace($costa)->create([
        'name' => 'Ana', 'email' => 'ana@example.com', 'last_seen_at' => now()->subHour(),
    ]);
    $bia = User::factory()->create(['name' => 'Bia', 'last_seen_at' => null]);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSeeLivewire(UserList::class)
        ->assertSeeInOrder(['Paulo', $household->name, 'Ana', 'ana@example.com', '10/03/2026', 'Costa family', $household->name, 'Bia', 'Never', 'No workspace'])
        ->assertSee('data-user-workspace="'.$costa->friendly_id.'"', false)
        ->assertSee(route('admin.workspaces.show', $costa), false)
        ->assertSee(Workspace::hexOf('green'), false);

    $this->get(route('admin.index'))
        ->assertOk()
        ->assertSeeLivewire(AdminHome::class)
        ->assertSee(route('admin.users.index'), false)
        ->assertSee(route('admin.workspaces.index'), false)
        ->assertDontSee(route('admin.modules.index'), false);
});

test('last seen is stored at most once a minute', function () {
    $this->travelTo(now()->startOfMinute());

    $user = User::factory()->create(['last_seen_at' => null]);
    $updatedAt = $user->updated_at;

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $firstSeen = $user->fresh()->last_seen_at;

    expect($firstSeen)->not->toBeNull()
        ->and($user->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    $this->travel(30)->seconds();
    $this->get(route('dashboard'))->assertOk();

    expect($user->fresh()->last_seen_at->equalTo($firstSeen))->toBeTrue();

    $this->travel(31)->seconds();
    $this->get(route('dashboard'))->assertOk();

    expect($user->fresh()->last_seen_at->greaterThan($firstSeen))->toBeTrue();

    $token = $user->createToken('iPhone')->plainTextToken;
    $this->travel(2)->minutes();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/user-probe')->assertOk();

    expect($user->fresh()->last_seen_at->equalTo(now()))->toBeTrue();
});
