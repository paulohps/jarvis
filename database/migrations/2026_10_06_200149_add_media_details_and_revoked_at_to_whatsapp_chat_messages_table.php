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
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->unsignedInteger('media_seconds')->nullable()->after('media_size');
            $table->string('media_waveform', 128)->nullable()->after('media_seconds');
            $table->text('media_thumbnail')->nullable()->after('media_waveform');
            $table->timestamp('revoked_at')->nullable()->after('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['media_seconds', 'media_waveform', 'media_thumbnail', 'revoked_at']);
        });
    }
};
