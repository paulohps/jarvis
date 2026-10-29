<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media of the chats: what WhatsApp says about it (type, size, name)
 * as the message arrives, and the file once downloaded to the private disk
 * (`media_path`). A delivery sent from the WhatsApp screen may carry a file
 * too, sent as a media with its body as the caption.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->string('media_mime', 127)->nullable()->after('body');
            $table->string('media_name')->nullable()->after('media_mime');
            $table->unsignedBigInteger('media_size')->nullable()->after('media_name');
            $table->string('media_path')->nullable()->after('media_size');
        });

        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('body');
            $table->string('media_mime', 127)->nullable()->after('media_path');
            $table->string('media_name')->nullable()->after('media_mime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime', 'media_name']);
        });

        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['media_mime', 'media_name', 'media_size', 'media_path']);
        });
    }
};
