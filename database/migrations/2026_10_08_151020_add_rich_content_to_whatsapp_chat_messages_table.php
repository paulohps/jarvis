<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a chat message says beyond its text: the message it answers
     * (its WhatsApp id and a copy of what it said), who it mentions,
     * whether it was forwarded, edited, pinned in the chat or starred by
     * the owner, and the content of a location, contact or poll
     * (`payload`).
     */
    public function up(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->string('quoted_external_id', 128)->nullable()->after('body');
            $table->json('quoted')->nullable()->after('quoted_external_id');
            $table->json('mentions')->nullable()->after('quoted');
            $table->json('payload')->nullable()->after('mentions');
            $table->boolean('is_forwarded')->default(false)->after('payload');
            $table->timestamp('edited_at')->nullable()->after('revoked_at');
            $table->timestamp('pinned_at')->nullable()->after('edited_at');
            $table->timestamp('pinned_until')->nullable()->after('pinned_at');
            $table->timestamp('starred_at')->nullable()->after('pinned_until');

            $table->index(['whatsapp_target_id', 'starred_at']);
            $table->index(['whatsapp_target_id', 'pinned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_target_id', 'starred_at']);
            $table->dropIndex(['whatsapp_target_id', 'pinned_at']);
            $table->dropColumn(['quoted_external_id', 'quoted', 'mentions', 'payload', 'is_forwarded', 'edited_at', 'pinned_at', 'pinned_until', 'starred_at']);
        });
    }
};
