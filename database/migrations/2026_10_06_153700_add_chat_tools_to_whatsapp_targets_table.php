<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the WhatsApp screen adds to a chat: pinned (no limit, unlike
 * WhatsApp's 3), a priority from 1 (highest) to 5 with a color, and the
 * reminders created from the chat or one of its messages.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('unread_count');
            $table->unsignedTinyInteger('priority')->nullable()->after('pinned_at');
            $table->string('priority_color', 16)->nullable()->after('priority');
        });

        Schema::create('whatsapp_chat_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chat_reminders');

        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropColumn(['pinned_at', 'priority', 'priority_color']);
        });
    }
};
