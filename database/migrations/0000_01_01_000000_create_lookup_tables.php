<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 18 lookup tables that replace every enum (database-schema: Lookup
 * tables). They run before `users`, which references `locales`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->lookupTables() as $tableName => $extraColumns) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $extraColumns) {
                $table->id();

                if ($tableName !== 'notification_types') {
                    $table->string('name');
                }

                $table->string('slug')->unique();

                $extraColumns($table);

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(array_keys($this->lookupTables())) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }

    /**
     * Each lookup table with the columns it has besides `id`, `name`, `slug`
     * and the timestamps (`notification_types` has no `name`: its label comes
     * from the notification class).
     *
     * @return array<string, Closure(Blueprint): void>
     */
    private function lookupTables(): array
    {
        $isActive = function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        };
        $requiresValue = function (Blueprint $table): void {
            $table->boolean('requires_value')->default(false);
        };
        $none = function (Blueprint $table): void {};

        return [
            'locales' => $isActive,
            'modules' => function (Blueprint $table): void {
                $table->text('description')->nullable();
                $table->boolean('is_available_on_mobile')->default(false);
                $table->smallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
            },
            'social_providers' => $isActive,
            'device_platforms' => $none,
            'ai_providers' => function (Blueprint $table): void {
                $table->boolean('requires_base_url')->default(false);
                $table->boolean('is_active')->default(true);
            },
            'notification_types' => function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
                $table->boolean('is_visible_in_profile')->default(true);
            },
            'notification_channels' => $isActive,
            'recurrence_frequencies' => $none,
            'weekdays' => $none,
            'delivery_statuses' => $none,
            'currencies' => function (Blueprint $table): void {
                $table->string('symbol', 8);
                $table->smallInteger('decimal_places')->default(2);
                $table->boolean('is_active')->default(true);
            },
            'invoice_statuses' => $none,
            'invoice_delivery_modes' => $none,
            'invoice_date_rules' => $requiresValue,
            'invoice_due_date_rules' => $requiresValue,
            'whatsapp_connection_statuses' => $none,
            'whatsapp_target_types' => $none,
            'whatsapp_delivery_sources' => $none,
        ];
    }
};
