<?php

namespace Database\Factories;

use App\Models\SlackConversation;
use App\Models\SlackMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlackMessage>
 */
class SlackMessageFactory extends Factory
{
    /**
     * A text someone sent a minute ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sentAt = now()->subMinute();

        return [
            'slack_conversation_id' => SlackConversation::factory(),
            'ts' => $sentAt->getTimestamp().'.'.fake()->unique()->numerify('######'),
            'thread_ts' => null,
            'slack_user_id' => 'U'.fake()->regexify('[A-Z0-9]{10}'),
            'subtype' => null,
            'text' => fake()->sentence(),
            'reply_count' => 0,
            'sent_at' => $sentAt,
        ];
    }

    /**
     * Sent by the account's own user.
     */
    public function fromAccountUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'slack_user_id' => fn (array $attributes): string => SlackConversation::query()->whereKey($attributes['slack_conversation_id'])->firstOrFail()->account->slack_user_id,
        ]);
    }

    /**
     * A reply in the thread of the message.
     */
    public function inThreadOf(SlackMessage $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'slack_conversation_id' => $parent->slack_conversation_id,
            'thread_ts' => $parent->ts,
        ]);
    }

    public function withFile(string $name = 'report.pdf', string $mime = 'application/pdf'): static
    {
        return $this->state(fn (array $attributes) => [
            'subtype' => 'file_share',
            'files' => [[
                'id' => 'F'.fake()->regexify('[A-Z0-9]{10}'),
                'name' => $name,
                'title' => $name,
                'mimetype' => $mime,
                'size' => 12345,
                'url_private' => 'https://files.slack.com/files-pri/T000-F000/'.$name,
                'url_private_download' => 'https://files.slack.com/files-pri/T000-F000/download/'.$name,
            ]],
        ]);
    }
}
