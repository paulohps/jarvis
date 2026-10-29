<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * The name of the workspace the factories share by default.
     */
    public const string HOUSEHOLD = 'Household';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lastName().' family',
            'color' => fake()->randomElement(array_keys(Workspace::COLORS)),
        ];
    }

    /**
     * The workspace the factories put shared data and users with modules in
     * when told none, created on first use: a test that does not care about
     * workspaces gets everything in a single one, as the household before.
     */
    public static function household(): Workspace
    {
        return Workspace::query()->where('name', self::HOUSEHOLD)->oldest('id')->first()
            ?? Workspace::factory()->create(['name' => self::HOUSEHOLD, 'color' => 'blue']);
    }
}
