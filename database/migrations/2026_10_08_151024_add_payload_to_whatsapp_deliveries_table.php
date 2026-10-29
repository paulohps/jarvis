<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a delivery sends beyond a text or a file (`payload`): the
     * message it answers, who it mentions, and a poll, location, contact or
     * sticker instead of a text.
     */
    public function up(): void
    {
        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('media_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
