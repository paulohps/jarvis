<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scheduled message may repeat (its recurrence decides when; `send_at`
     * stays only on the ones scheduled before) and may be written by the AI
     * at each sending from `ai_prompt`, without a body.
     */
    public function up(): void
    {
        Schema::table('chat_scheduled_messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
            $table->text('ai_prompt')->nullable()->after('body');
            $table->timestamp('send_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('ai_prompt');
        });
    }
};
