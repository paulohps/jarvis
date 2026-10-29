<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The chat list of the WhatsApp screen reads these instead of the messages:
 * when the chat last had a message, either way, and how many came in since
 * the user last read it in Jarvis. Filled from the messages already kept.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable()->after('is_synced');
            $table->unsignedInteger('unread_count')->default(0)->after('last_message_at');

            $table->index(['whatsapp_account_id', 'last_message_at']);
        });

        DB::statement('UPDATE whatsapp_targets SET last_message_at = (SELECT max(sent_at) FROM whatsapp_chat_messages WHERE whatsapp_chat_messages.whatsapp_target_id = whatsapp_targets.id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_account_id', 'last_message_at']);
            $table->dropColumn(['last_message_at', 'unread_count']);
        });
    }
};
