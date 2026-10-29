<?php

use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Row count of every table the lookup seeder fills.
 *
 * @return array<string, int>
 */
function lookupRowCounts(): array
{
    return collect([
        'locales', 'modules', 'social_providers', 'device_platforms', 'ai_providers',
        'notification_types', 'notification_channels', 'recurrence_frequencies', 'weekdays',
        'delivery_statuses', 'currencies', 'invoice_statuses', 'invoice_delivery_modes',
        'invoice_date_rules', 'invoice_due_date_rules', 'whatsapp_connection_statuses',
        'whatsapp_target_types', 'whatsapp_delivery_sources',
    ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

test('lookup seeders are idempotent', function () {
    $this->seed(LookupSeeder::class);
    $countsAfterFirstSeed = lookupRowCounts();

    $this->seed(LookupSeeder::class);

    expect(lookupRowCounts())->toBe($countsAfterFirstSeed)
        ->and($countsAfterFirstSeed['locales'])->toBe(2)
        ->and($countsAfterFirstSeed['weekdays'])->toBe(7)
        ->and($countsAfterFirstSeed['notification_types'])->toBe(0);
});

test('db seed can run twice', function () {
    $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();
    $countsAfterFirstSeed = lookupRowCounts();

    $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

    expect(lookupRowCounts())->toBe($countsAfterFirstSeed);
});

test('weekday ids follow iso numbering', function () {
    $this->seed(LookupSeeder::class);

    expect(DB::table('weekdays')->where('id', 1)->value('slug'))->toBe('monday')
        ->and(DB::table('weekdays')->where('id', 7)->value('slug'))->toBe('sunday')
        ->and(DB::table('weekdays')->orderBy('id')->pluck('id')->all())->toBe(range(1, 7));
});

test('lookups carry the schema flags', function () {
    $this->seed(LookupSeeder::class);

    expect(DB::table('modules')->where('is_available_on_mobile', true)->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['whatsapp', 'shopping', 'reminders', 'chats'])
        ->and(DB::table('currencies')->pluck('symbol', 'slug')->all())->toEqual(['USD' => '$', 'EUR' => '€', 'BRL' => 'R$'])
        ->and(DB::table('recurrence_frequencies')->pluck('slug')->all())->toEqualCanonicalizing(['once', 'daily', 'weekly', 'monthly', 'custom_cron'])
        ->and(DB::table('invoice_date_rules')->where('requires_value', true)->pluck('slug')->all())->toBe(['day_of_current_month'])
        ->and(DB::table('invoice_due_date_rules')->where('requires_value', true)->pluck('slug')->all())->toEqualCanonicalizing(['days_after_issue', 'day_of_next_month'])
        ->and(DB::table('ai_providers')->where('requires_base_url', true)->pluck('slug')->all())->toEqualCanonicalizing(['ollama', 'openai-compatible']);
});

test('seeded ai providers are drivers laravel ai knows', function () {
    $this->seed(LookupSeeder::class);

    $configuredDrivers = collect(config('ai.providers'))->pluck('driver');

    expect(DB::table('ai_providers')->pluck('slug')->diff($configuredDrivers))->toBeEmpty();
});
