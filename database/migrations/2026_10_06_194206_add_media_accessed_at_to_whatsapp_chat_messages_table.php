<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->timestamp('media_accessed_at')->nullable()->after('media_path');
        });

        // Files kept before: as if last opened when they were stored.
        DB::table('whatsapp_chat_messages')
            ->whereNotNull('media_path')
            ->update(['media_accessed_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->dropColumn('media_accessed_at');
        });
    }
};
