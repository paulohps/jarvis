<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group chats are kept too: who in the group sent each message, as its JID
 * (the phone one when WhatsApp gives it) and the name it showed. Null for
 * one-to-one chats and for what the number sent.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->string('sender_jid')->nullable()->after('from_me');
            $table->string('sender_name')->nullable()->after('sender_jid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['sender_jid', 'sender_name']);
        });
    }
};
