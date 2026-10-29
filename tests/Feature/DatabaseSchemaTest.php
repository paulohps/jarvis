<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\FriendlyId;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('every lookup table has a unique slug and the extra flags of the schema', function () {
    $lookupTables = [
        'locales', 'modules', 'social_providers', 'device_platforms', 'ai_providers',
        'notification_types', 'notification_channels', 'recurrence_frequencies', 'weekdays',
        'delivery_statuses', 'currencies', 'invoice_statuses', 'invoice_delivery_modes',
        'invoice_date_rules', 'invoice_due_date_rules', 'whatsapp_connection_statuses',
        'whatsapp_target_types', 'whatsapp_delivery_sources',
    ];

    foreach ($lookupTables as $table) {
        expect(Schema::hasColumns($table, ['id', 'slug', 'created_at', 'updated_at']))->toBeTrue("{$table} misses base columns")
            ->and(collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['columns'] === ['slug'] && $index['unique']))->toBeTrue("{$table}.slug is not unique");
    }

    expect(Schema::hasColumn('notification_types', 'name'))->toBeFalse()
        ->and(Schema::hasColumns('modules', ['is_available_on_mobile', 'sort_order', 'description']))->toBeTrue()
        ->and(Schema::hasColumns('invoice_date_rules', ['requires_value']))->toBeTrue()
        ->and(Schema::hasColumns('invoice_due_date_rules', ['requires_value']))->toBeTrue()
        ->and(Schema::hasColumns('ai_providers', ['requires_base_url']))->toBeTrue()
        ->and(Schema::hasColumns('currencies', ['symbol', 'decimal_places']))->toBeTrue()
        ->and(Schema::hasColumns('notification_types', ['is_visible_in_profile']))->toBeTrue();
});

test('weekdays accept fixed iso ids', function () {
    DB::table('weekdays')->insert([
        ['id' => 7, 'name' => 'Sunday', 'slug' => 'sunday'],
        ['id' => 1, 'name' => 'Monday', 'slug' => 'monday'],
    ]);

    expect(DB::table('weekdays')->where('id', 7)->value('slug'))->toBe('sunday')
        ->and(DB::table('weekdays')->where('id', 1)->value('slug'))->toBe('monday');
});

/**
 * Inserts a lookup row and returns its id.
 */
function lookupId(string $table, string $slug, array $attributes = []): int
{
    return DB::table($table)->insertGetId([
        ...($table === 'notification_types' ? [] : ['name' => str($slug)->headline()->toString()]),
        'slug' => $slug,
        ...$attributes,
    ]);
}

test('only one ai provider connection can be active', function () {
    $user = User::factory()->create();

    $connection = fn (string $name, bool $isActive): array => [
        'friendly_id' => FriendlyId::generate('aic'),
        'ai_provider_id' => lookupId('ai_providers', "provider-{$name}"),
        'name' => $name,
        'model' => 'some-model',
        'models' => json_encode(['some-model']),
        'is_active' => $isActive,
        'created_by_id' => $user->id,
    ];

    DB::table('ai_provider_connections')->insert($connection('first', true));
    DB::table('ai_provider_connections')->insert($connection('inactive', false));
    DB::table('ai_provider_connections')->insert($connection('another inactive', false));

    expect(DB::table('ai_provider_connections')->count())->toBe(3);

    DB::table('ai_provider_connections')->insert($connection('second', true));
})->throws(UniqueConstraintViolationException::class);

test('users carry the profile columns and default to the utc timezone', function () {
    $user = User::factory()->create(['password' => null]);

    $row = DB::table('users')->find($user->id);

    expect($row->timezone)->toBe('UTC')
        ->and($row->password)->toBeNull()
        ->and($row->is_super_admin)->toBeFalse()
        ->and($row->friendly_id)->toStartWith('usr_')
        ->and(Schema::hasColumns('users', [
            'avatar_path', 'locale_id', 'whatsapp_notification_phone', 'deactivated_at', 'last_seen_at', 'deleted_at',
        ]))->toBeTrue();
});

test('identity pivots and social accounts reject duplicates', function (string $table, Closure $row) {
    $user = User::factory()->create();

    DB::table($table)->insert($row($user));
    DB::table($table)->insert($row($user));
})->with([
    'workspace member' => ['workspace_user', fn (User $user) => [
        'workspace_id' => WorkspaceFactory::household()->id,
        'user_id' => $user->id,
    ]],
    'second default workspace' => ['workspace_user', fn (User $user) => [
        'workspace_id' => Workspace::factory()->create()->id,
        'user_id' => $user->id,
        'is_default' => true,
    ]],
    'social account per provider user' => ['social_accounts', fn (User $user) => [
        'user_id' => User::factory()->create()->id,
        'social_provider_id' => DB::table('social_providers')->where('slug', 'google')->value('id') ?? lookupId('social_providers', 'google'),
        'provider_user_id' => 'google-123',
    ]],
    'social account per user' => ['social_accounts', fn (User $user) => [
        'user_id' => $user->id,
        'social_provider_id' => DB::table('social_providers')->where('slug', 'google')->value('id') ?? lookupId('social_providers', 'google'),
        'provider_user_id' => fake()->uuid(),
    ]],
])->throws(UniqueConstraintViolationException::class);

test('devices allow many null push tokens but no repeated token', function () {
    $user = User::factory()->create();
    $platformId = lookupId('device_platforms', 'ios');

    $device = fn (?string $pushToken): array => [
        'friendly_id' => FriendlyId::generate('dev'),
        'user_id' => $user->id,
        'device_platform_id' => $platformId,
        'name' => 'iPhone',
        'push_token' => $pushToken,
    ];

    DB::table('devices')->insert([$device(null), $device(null), $device('token-a')]);

    expect(DB::table('devices')->count())->toBe(3);

    DB::table('devices')->insert($device('token-a'));
})->throws(UniqueConstraintViolationException::class);

/**
 * Whether the table has an index on exactly these columns, in this order.
 *
 * @param  list<string>  $columns
 */
function hasIndexOn(string $table, array $columns, bool $unique): bool
{
    return collect(Schema::getIndexes($table))
        ->contains(fn (array $index) => $index['columns'] === $columns && $index['unique'] === $unique);
}

test('notification center and recurrence tables have the schema indexes', function (string $table, array $columns, bool $unique) {
    expect(hasIndexOn($table, $columns, $unique))->toBeTrue();
})->with([
    ['notification_type_channels', ['notification_type_id', 'notification_channel_id'], true],
    ['notification_templates', ['notification_type_channel_id', 'locale_id'], true],
    ['notification_preferences', ['user_id', 'notification_type_id', 'notification_channel_id'], true],
    ['notifications', ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], false],
    ['notifications', ['notifiable_type', 'notifiable_id', 'created_at'], false],
    ['recurrences', ['schedulable_type', 'schedulable_id'], true],
    ['recurrences', ['is_paused', 'next_run_at'], false],
    ['recurrence_weekday', ['recurrence_id', 'weekday_id'], true],
]);

test('notifications use a uuid primary key', function () {
    expect(Schema::getColumnType('notifications', 'id'))->toBe('uuid');
});

test('a schedulable owner has at most one recurrence', function () {
    $recurrence = [
        'schedulable_type' => 'reminder',
        'schedulable_id' => 1,
        'recurrence_frequency_id' => lookupId('recurrence_frequencies', 'daily'),
        'timezone' => 'UTC',
        'starts_at' => now(),
    ];

    DB::table('recurrences')->insert($recurrence);
    DB::table('recurrences')->insert($recurrence);
})->throws(UniqueConstraintViolationException::class);

/**
 * Inserts an invoice recurrence with its payer, issuer and lookups.
 */
function insertInvoiceRecurrence(User $user): int
{
    $payerId = DB::table('payers')->insertGetId([
        'workspace_id' => WorkspaceFactory::household()->id,
        'friendly_id' => FriendlyId::generate('pay'),
        'name' => 'Acme Corp',
        'address' => "1 Main St\nSpringfield",
        'created_by_id' => $user->id,
    ]);

    $issuerId = DB::table('issuers')->insertGetId([
        'workspace_id' => WorkspaceFactory::household()->id,
        'friendly_id' => FriendlyId::generate('iss'),
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'postal_code' => '01000-000',
        'street' => 'Rua A, 1',
        'district' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'created_by_id' => $user->id,
    ]);

    return DB::table('invoice_recurrences')->insertGetId([
        'workspace_id' => WorkspaceFactory::household()->id,
        'friendly_id' => FriendlyId::generate('irec'),
        'name' => 'Monthly consulting',
        'payer_id' => $payerId,
        'issuer_id' => $issuerId,
        'currency_id' => lookupId('currencies', 'USD', ['symbol' => '$']),
        'amount' => '1500.00',
        'service_description' => 'Consulting',
        'email_subject' => 'Invoice',
        'email_body' => 'Attached.',
        'invoice_date_rule_id' => lookupId('invoice_date_rules', 'execution_day'),
        'invoice_due_date_rule_id' => lookupId('invoice_due_date_rules', 'last_day_of_issue_month'),
        'invoice_delivery_mode_id' => lookupId('invoice_delivery_modes', 'automatic'),
        'locale_id' => DB::table('locales')->where('slug', 'en')->value('id'),
        'created_by_id' => $user->id,
    ]);
}

/**
 * Inserts an invoice with a snapshot of payer and issuer.
 */
function insertInvoice(int $sequence, ?int $invoiceRecurrenceId = null, ?string $periodKey = null, ?int $workspaceId = null): int
{
    $statusId = DB::table('invoice_statuses')->where('slug', 'draft')->value('id') ?? lookupId('invoice_statuses', 'draft');
    $currencyId = DB::table('currencies')->where('slug', 'USD')->value('id') ?? lookupId('currencies', 'USD', ['symbol' => '$']);
    $localeId = DB::table('locales')->where('slug', 'en')->value('id') ?? lookupId('locales', 'en');

    return DB::table('invoices')->insertGetId([
        'workspace_id' => $workspaceId ?? WorkspaceFactory::household()->id,
        'friendly_id' => FriendlyId::generate('inv'),
        'invoice_recurrence_id' => $invoiceRecurrenceId,
        'period_key' => $periodKey,
        'number' => sprintf('202610-%03d', $sequence),
        'number_year_month' => '202610',
        'number_sequence' => $sequence,
        'invoice_status_id' => $statusId,
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-31',
        'currency_id' => $currencyId,
        'amount' => '1500.00',
        'service_description' => 'Consulting',
        'email_subject' => 'Invoice',
        'email_body' => 'Attached.',
        'payer_name' => 'Acme Corp',
        'payer_address' => "1 Main St\nSpringfield",
        'issuer_name' => 'Jane Doe',
        'issuer_email' => 'jane@example.com',
        'issuer_postal_code' => '01000-000',
        'issuer_street' => 'Rua A, 1',
        'issuer_district' => 'Centro',
        'issuer_city' => 'São Paulo',
        'issuer_state' => 'SP',
        'locale_id' => $localeId,
    ]);
}

test('invoice recurrence cannot produce two invoices for the same period', function () {
    $invoiceRecurrenceId = insertInvoiceRecurrence(User::factory()->create());

    insertInvoice(1, $invoiceRecurrenceId, '2026-10');
    insertInvoice(2, $invoiceRecurrenceId, '2026-11');

    expect(DB::table('invoices')->count())->toBe(2);

    insertInvoice(3, $invoiceRecurrenceId, '2026-10');
})->throws(UniqueConstraintViolationException::class);

test('manual invoices with null period do not collide', function () {
    insertInvoice(1);
    insertInvoice(2);

    expect(DB::table('invoices')->whereNull('period_key')->count())->toBe(2);
});

test('the same invoice number may exist in another workspace', function () {
    insertInvoice(1);
    insertInvoice(1, workspaceId: Workspace::factory()->create()->id);

    expect(DB::table('invoices')->where('number', '202610-001')->count())->toBe(2);
});

test('invoice numbers are unique per workspace and per month sequence', function (array $attributes) {
    insertInvoice(1);

    DB::table('invoices')->where('number_sequence', 1)->update(['number' => '202610-099', 'number_sequence' => 99]);
    insertInvoice(1);

    DB::table('invoices')->where('number', '202610-001')->update($attributes);
})->with([
    'same number' => [['number' => '202610-099']],
    'same year month and sequence' => [['number_sequence' => 99]],
])->throws(UniqueConstraintViolationException::class);

test('invoice tables have the schema uniques, soft deletes and money precision', function () {
    expect(hasIndexOn('invoice_number_sequences', ['workspace_id', 'year_month'], true))->toBeTrue()
        ->and(hasIndexOn('invoices', ['workspace_id', 'number'], true))->toBeTrue()
        ->and(hasIndexOn('invoice_recurrence_recipients', ['invoice_recurrence_id', 'email'], true))->toBeTrue()
        ->and(hasIndexOn('invoice_recipients', ['invoice_id', 'email'], true))->toBeTrue()
        ->and(hasIndexOn('invoices', ['invoice_status_id'], false))->toBeTrue()
        ->and(Schema::hasColumn('payers', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('issuers', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('invoice_recurrences', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'deleted_at'))->toBeFalse();

    $amountColumn = collect(Schema::getColumns('invoices'))->firstWhere('name', 'amount');

    expect($amountColumn['type'])->toBe('numeric(12,2)');
});

test('whatsapp tables have the schema uniques, index and soft deletes', function () {
    expect(hasIndexOn('whatsapp_accounts', ['instance_name'], true))->toBeTrue()
        ->and(hasIndexOn('whatsapp_targets', ['whatsapp_account_id', 'jid'], true))->toBeTrue()
        ->and(hasIndexOn('whatsapp_message_whatsapp_target', ['whatsapp_message_id', 'whatsapp_target_id'], true))->toBeTrue()
        ->and(hasIndexOn('whatsapp_deliveries', ['whatsapp_message_id', 'whatsapp_target_id', 'scheduled_for'], true))->toBeTrue()
        ->and(hasIndexOn('whatsapp_deliveries', ['whatsapp_account_id', 'created_at'], false))->toBeTrue()
        ->and(Schema::hasColumn('whatsapp_messages', 'deleted_at'))->toBeTrue();
});

test('deleting the whatsapp notification account clears it on the user', function () {
    $user = User::factory()->create();

    $accountId = DB::table('whatsapp_accounts')->insertGetId([
        'friendly_id' => FriendlyId::generate('wac'),
        'user_id' => $user->id,
        'nickname' => 'Personal',
        'instance_name' => 'jarvis-personal',
        'whatsapp_connection_status_id' => lookupId('whatsapp_connection_statuses', 'open'),
    ]);

    DB::table('users')->where('id', $user->id)->update(['whatsapp_notification_account_id' => $accountId]);
    DB::table('whatsapp_accounts')->where('id', $accountId)->delete();

    expect(DB::table('users')->where('id', $user->id)->value('whatsapp_notification_account_id'))->toBeNull();
});

test('shopping and reminder tables have the schema indexes and soft deletes', function () {
    expect(hasIndexOn('shopping_list_items', ['shopping_list_id', 'checked_at', 'position'], false))->toBeTrue()
        ->and(hasIndexOn('reminder_occurrences', ['completed_at', 'due_at'], false))->toBeTrue()
        ->and(Schema::hasColumn('shopping_lists', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'deleted_at'))->toBeTrue();
});

test('a reminder cannot have two occurrences due at the same time', function () {
    $reminderId = DB::table('reminders')->insertGetId([
        'workspace_id' => WorkspaceFactory::household()->id,
        'friendly_id' => FriendlyId::generate('rem'),
        'title' => 'Pay rent',
        'created_by_id' => User::factory()->create()->id,
    ]);

    $occurrence = fn (?string $dueAt): array => [
        'friendly_id' => FriendlyId::generate('rocc'),
        'reminder_id' => $reminderId,
        'due_at' => $dueAt,
    ];

    DB::table('reminder_occurrences')->insert([$occurrence(null), $occurrence(null), $occurrence('2026-10-05 09:00:00')]);

    expect(DB::table('reminder_occurrences')->count())->toBe(3);

    DB::table('reminder_occurrences')->insert($occurrence('2026-10-05 09:00:00'));
})->throws(UniqueConstraintViolationException::class);
