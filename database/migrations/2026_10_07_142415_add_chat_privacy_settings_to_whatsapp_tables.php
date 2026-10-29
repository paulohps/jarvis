<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether what the user does on the chat screen reaches WhatsApp: the
     * read receipts and "typing…". Off by default, per number, and each chat
     * may differ from its number (null follows it). `read_up_to` is how far
     * WhatsApp was told a chat was read.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('sends_read_receipts')->default(false)->after('proxy_password');
            $table->boolean('sends_typing')->default(false)->after('sends_read_receipts');
        });

        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->boolean('sends_read_receipts')->nullable()->after('picture_checked_at');
            $table->boolean('sends_typing')->nullable()->after('sends_read_receipts');
            $table->timestamp('read_up_to')->nullable()->after('sends_typing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropColumn(['sends_read_receipts', 'sends_typing', 'read_up_to']);
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['sends_read_receipts', 'sends_typing']);
        });
    }
};
