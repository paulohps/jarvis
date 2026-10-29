<?php

namespace Database\Factories;

use App\Models\SlackAccount;
use App\Models\SlackUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlackUser>
 */
class SlackUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->firstName();

        return [
            'slack_account_id' => SlackAccount::factory(),
            'slack_user_id' => 'U'.Str::upper(Str::random(10)),
            'name' => Str::lower($name),
            'real_name' => $name.' '.fake()->lastName(),
            'display_name' => $name,
            'picture_url' => null,
            'is_bot' => false,
            'is_deleted' => false,
        ];
    }
}
