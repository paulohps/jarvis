<?php

namespace Database\Seeders;

use App\Support\LookupCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds every lookup table (database-schema: Lookup Table Seeds). Rows are
 * matched by `slug`, so running it again updates them instead of duplicating.
 *
 * The rows are written without model events, so the lookup memo is flushed
 * at the end.
 *
 * `notification_types` is left out on purpose: `notifications:provision`
 * creates its rows from the registered notification classes.
 */
class LookupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->lookups() as $table => $rows) {
            foreach ($rows as $row) {
                $this->upsertBySlug($table, $row);
            }
        }

        app(LookupCache::class)->flush();
    }

    /**
     * Inserts or updates the row with the given slug, keeping `created_at`
     * from the first insert.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsertBySlug(string $table, array $row): void
    {
        $now = now();

        DB::table($table)->updateOrInsert(
            ['slug' => $row['slug']],
            fn (bool $exists): array => [
                ...$row,
                ...($exists ? [] : ['created_at' => $now]),
                'updated_at' => $now,
            ],
        );
    }

    /**
     * The rows of each lookup table.
     *
     * `ai_providers` lists the `laravel/ai` drivers with text generation
     * that work with an API key, a model and an optional base URL. Azure
     * (deployment and API version) and Bedrock (AWS region and credentials)
     * need settings `ai_provider_connections` does not store.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function lookups(): array
    {
        return [
            'locales' => [
                ['slug' => 'en', 'name' => 'English'],
                ['slug' => 'pt_BR', 'name' => 'Português (Brasil)'],
            ],
            'modules' => [
                ['slug' => 'invoices', 'name' => 'Invoices', 'is_available_on_mobile' => false, 'sort_order' => 1],
                ['slug' => 'whatsapp', 'name' => 'WhatsApp', 'is_available_on_mobile' => true, 'sort_order' => 2],
                ['slug' => 'shopping', 'name' => 'Shopping list', 'is_available_on_mobile' => true, 'sort_order' => 3],
                ['slug' => 'reminders', 'name' => 'Agenda', 'is_available_on_mobile' => true, 'sort_order' => 4],
                ['slug' => 'chats', 'name' => 'Chats', 'is_available_on_mobile' => true, 'sort_order' => 5],
            ],
            'chat_providers' => [
                ['slug' => 'whatsapp', 'name' => 'WhatsApp', 'sort_order' => 1, 'is_active' => true],
                ['slug' => 'slack', 'name' => 'Slack', 'sort_order' => 2, 'is_active' => true],
            ],
            'social_providers' => [
                ['slug' => 'google', 'name' => 'Google'],
            ],
            'device_platforms' => [
                ['slug' => 'ios', 'name' => 'iOS'],
                ['slug' => 'android', 'name' => 'Android'],
                ['slug' => 'web', 'name' => 'Browser'],
            ],
            'ai_purposes' => [
                ['slug' => 'voice_command', 'name' => 'Voice commands'],
                ['slug' => 'whatsapp_reply', 'name' => 'WhatsApp replies'],
                ['slug' => 'chat_reply', 'name' => 'Chat replies'],
            ],
            'ai_providers' => [
                ['slug' => 'anthropic', 'name' => 'Anthropic', 'requires_base_url' => false],
                ['slug' => 'deepseek', 'name' => 'DeepSeek', 'requires_base_url' => false],
                ['slug' => 'gemini', 'name' => 'Gemini', 'requires_base_url' => false],
                ['slug' => 'groq', 'name' => 'Groq', 'requires_base_url' => false],
                ['slug' => 'mistral', 'name' => 'Mistral', 'requires_base_url' => false],
                ['slug' => 'ollama', 'name' => 'Ollama', 'requires_base_url' => true],
                ['slug' => 'openai', 'name' => 'OpenAI', 'requires_base_url' => false],
                ['slug' => 'openai-compatible', 'name' => 'OpenAI compatible', 'requires_base_url' => true],
                ['slug' => 'openrouter', 'name' => 'OpenRouter', 'requires_base_url' => false],
                ['slug' => 'xai', 'name' => 'xAI', 'requires_base_url' => false],
            ],
            'mail_service_types' => [
                ['slug' => 'smtp', 'name' => 'SMTP'],
                ['slug' => 'gmail', 'name' => 'Google (Gmail)'],
                ['slug' => 'resend', 'name' => 'Resend'],
            ],
            'notification_channels' => [
                ['slug' => 'database', 'name' => 'In the app'],
                ['slug' => 'mail', 'name' => 'E-mail'],
                ['slug' => 'push', 'name' => 'Push'],
                ['slug' => 'whatsapp', 'name' => 'WhatsApp'],
            ],
            'recurrence_frequencies' => [
                ['slug' => 'once', 'name' => 'Once'],
                ['slug' => 'daily', 'name' => 'Daily'],
                ['slug' => 'weekly', 'name' => 'Weekly'],
                ['slug' => 'monthly', 'name' => 'Monthly'],
                ['slug' => 'custom_cron', 'name' => 'Custom (cron)'],
            ],
            'weekdays' => [
                ['id' => 1, 'slug' => 'monday', 'name' => 'Monday'],
                ['id' => 2, 'slug' => 'tuesday', 'name' => 'Tuesday'],
                ['id' => 3, 'slug' => 'wednesday', 'name' => 'Wednesday'],
                ['id' => 4, 'slug' => 'thursday', 'name' => 'Thursday'],
                ['id' => 5, 'slug' => 'friday', 'name' => 'Friday'],
                ['id' => 6, 'slug' => 'saturday', 'name' => 'Saturday'],
                ['id' => 7, 'slug' => 'sunday', 'name' => 'Sunday'],
            ],
            'delivery_statuses' => [
                ['slug' => 'pending', 'name' => 'Pending'],
                ['slug' => 'sent', 'name' => 'Sent'],
                ['slug' => 'failed', 'name' => 'Failed'],
            ],
            'currencies' => [
                ['slug' => 'USD', 'name' => 'American dollar', 'symbol' => '$', 'decimal_places' => 2],
                ['slug' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2],
                ['slug' => 'BRL', 'name' => 'Brazilian real', 'symbol' => 'R$', 'decimal_places' => 2],
            ],
            'invoice_statuses' => [
                ['slug' => 'draft', 'name' => 'Draft'],
                ['slug' => 'pending_review', 'name' => 'Pending review'],
                ['slug' => 'sent', 'name' => 'Sent'],
                ['slug' => 'failed', 'name' => 'Failed'],
                ['slug' => 'cancelled', 'name' => 'Cancelled'],
            ],
            'invoice_delivery_modes' => [
                ['slug' => 'automatic', 'name' => 'Automatic'],
                ['slug' => 'requires_review', 'name' => 'Requires review'],
            ],
            'invoice_date_rules' => [
                ['slug' => 'execution_day', 'name' => 'Execution day', 'requires_value' => false],
                ['slug' => 'day_of_current_month', 'name' => 'Day of the current month', 'requires_value' => true],
            ],
            'invoice_due_date_rules' => [
                ['slug' => 'days_after_issue', 'name' => 'Days after issue', 'requires_value' => true],
                ['slug' => 'day_of_next_month', 'name' => 'Day of the next month', 'requires_value' => true],
                ['slug' => 'last_day_of_issue_month', 'name' => 'Last day of the issue month', 'requires_value' => false],
            ],
            'whatsapp_connection_statuses' => [
                ['slug' => 'connecting', 'name' => 'Connecting'],
                ['slug' => 'open', 'name' => 'Connected'],
                ['slug' => 'closed', 'name' => 'Disconnected'],
            ],
            'whatsapp_target_types' => [
                ['slug' => 'contact', 'name' => 'Contact'],
                ['slug' => 'group', 'name' => 'Group'],
            ],
            'whatsapp_delivery_sources' => [
                ['slug' => 'scheduled', 'name' => 'Scheduled'],
                ['slug' => 'manual', 'name' => 'Manual'],
                ['slug' => 'notification', 'name' => 'Notification'],
                ['slug' => 'voice', 'name' => 'Voice'],
                ['slug' => 'chat', 'name' => 'Chat'],
            ],
        ];
    }
}
