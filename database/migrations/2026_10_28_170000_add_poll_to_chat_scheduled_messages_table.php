<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A poll a message scheduled in the inbox sends instead of a text, on
     * the channels that have polls.
     */
    public function up(): void
    {
        Schema::table('chat_scheduled_messages', function (Blueprint $table) {
            $table->json('poll')->nullable()->after('ai_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('poll');
        });
    }
};
