<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How urgent the AI found an unread conversation and what it wants,
     * apart from the priority the user gives it, as on the WhatsApp chats
     * (a WhatsApp entry copies them from its target).
     */
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->unsignedTinyInteger('ai_urgency')->nullable()->after('priority');
            $table->string('ai_summary', 200)->nullable()->after('ai_urgency');
            $table->timestamp('ai_triaged_at')->nullable()->after('ai_summary');
            $table->timestamp('ai_triaged_through')->nullable()->after('ai_triaged_at');
            $table->timestamp('ai_triage_requested_at')->nullable()->after('ai_triaged_through');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_urgency', 'ai_summary', 'ai_triaged_at', 'ai_triaged_through', 'ai_triage_requested_at']);
        });
    }
};
