<?php

namespace Database\Factories;

use App\Models\Locale;
use App\Models\Module;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    use ResolvesLookups;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale_id' => fn (): int => static::lookupId(Locale::class, Locale::EN, ['name' => 'English']),
            'timezone' => 'UTC',
            'is_super_admin' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is the super admin, a member of the household
     * workspace (WorkspaceFactory::household()), where they have every
     * module.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ])->afterCreating(function (User $user): void {
            if (! $user->memberships()->exists()) {
                static::joinWorkspace($user, WorkspaceFactory::household(), []);
            }
        });
    }

    /**
     * A user the super admin deactivated (US-2.2).
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }

    /**
     * A user with exactly these modules switched on, in the household
     * workspace (WorkspaceFactory::household()) — their default.
     *
     * @param  list<string>  $moduleSlugs
     */
    public function withModules(array $moduleSlugs): static
    {
        return $this->afterCreating(function (User $user) use ($moduleSlugs): void {
            static::joinWorkspace($user, WorkspaceFactory::household(), $moduleSlugs);
        });
    }

    /**
     * A member of `$workspace` with these modules switched on there. The
     * first workspace a user joins is their default.
     *
     * @param  list<string>  $moduleSlugs
     */
    public function inWorkspace(Workspace $workspace, array $moduleSlugs = [], ?string $color = null): static
    {
        return $this->afterCreating(function (User $user) use ($workspace, $moduleSlugs, $color): void {
            static::joinWorkspace($user, $workspace, $moduleSlugs, $color);
        });
    }

    /**
     * Makes the user a member of the workspace with exactly these modules,
     * their default when it is their first.
     *
     * @param  list<string>  $moduleSlugs
     */
    public static function joinWorkspace(User $user, Workspace $workspace, array $moduleSlugs, ?string $color = null): WorkspaceMembership
    {
        $membership = WorkspaceMembership::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['color' => $color, 'is_default' => ! WorkspaceMembership::query()->where('user_id', $user->id)->exists()],
        );

        $membership->modules()->sync(array_map(
            fn (string $slug): int => static::lookupId(Module::class, $slug),
            $moduleSlugs,
        ));

        $user->forgetMemberships();

        return $membership;
    }

    /**
     * A user without a password who signs in with a linked Google account.
     */
    public function googleOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
        ])->afterCreating(function (User $user): void {
            SocialAccount::factory()->for($user)->create(['email' => $user->email]);
        });
    }
}
