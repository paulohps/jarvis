<?php

namespace Database\Factories;

use App\Models\MailService;
use App\Models\MailServiceType;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MailService>
 */
class MailServiceFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A user's own SMTP server on port 587 (STARTTLS).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $password = Str::random(16);

        return [
            'mail_service_type_id' => fn (): int => static::lookupId(MailServiceType::class, MailServiceType::SMTP, ['name' => 'SMTP']),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => MailService::ENCRYPTION_TLS,
            'username' => fake()->userName(),
            'secret' => $password,
            'secret_last_four' => substr($password, -4),
            'is_global' => false,
        ];
    }

    /**
     * Offered to everyone (only the super admin marks one global).
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_global' => true,
        ]);
    }

    /**
     * Resend, with its API key.
     */
    public function resend(): static
    {
        $apiKey = 're_'.Str::random(32);

        return $this->state(fn (array $attributes) => [
            'mail_service_type_id' => static::lookupId(MailServiceType::class, MailServiceType::RESEND, ['name' => 'Resend']),
            'host' => null,
            'port' => null,
            'encryption' => null,
            'username' => null,
            'secret' => $apiKey,
            'secret_last_four' => substr($apiKey, -4),
        ]);
    }

    /**
     * A connected Google account, with its refresh token.
     */
    public function gmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'mail_service_type_id' => static::lookupId(MailServiceType::class, MailServiceType::GMAIL, ['name' => 'Google (Gmail)']),
            'from_address' => 'someone@gmail.com',
            'host' => null,
            'port' => null,
            'encryption' => null,
            'username' => null,
            'secret' => '1//refresh-'.Str::random(32),
            'secret_last_four' => null,
        ]);
    }
}
