<?php

namespace Database\Factories;

use App\Models\Locale;
use App\Models\NotificationTemplate;
use App\Models\NotificationTypeChannel;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    use ResolvesLookups;

    /**
     * The English template of a new type × channel pair.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_type_channel_id' => NotificationTypeChannel::factory(),
            'locale_id' => fn (): int => static::lookupId(Locale::class, Locale::EN, ['name' => 'English']),
            'title' => fake()->sentence(4),
            'body' => '<p>'.fake()->sentence().'</p>',
            'actions' => null,
            'updated_by_id' => null,
        ];
    }
}
