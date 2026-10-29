<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether what is read on the phone is read in Jarvis too, per number,
     * and the file a scheduled message sends (an image, a video, a document
     * or an audio, sent as a voice note), the body then its caption.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('syncs_phone_reads')->default(true)->after('notifies_messages');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('body');
            $table->string('media_mime', 128)->nullable()->after('media_path');
            $table->string('media_name')->nullable()->after('media_mime');
            $table->unsignedInteger('media_size')->nullable()->after('media_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime', 'media_name', 'media_size']);
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('syncs_phone_reads');
        });
    }
};
