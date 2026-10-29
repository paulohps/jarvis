<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->foreignId('whatsapp_account_id')->nullable()->after('is_active')->constrained('whatsapp_accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_account_id');
        });
    }
};
