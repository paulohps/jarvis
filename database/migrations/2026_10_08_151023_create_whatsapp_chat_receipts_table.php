<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a message the number sent reached each person and when they read
     * or played it, for its info screen: the contact in a one-to-one chat,
     * each participant in a group (`participant`: their JID).
     */
    public function up(): void
    {
        Schema::create('whatsapp_chat_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_chat_message_id')->constrained()->cascadeOnDelete();
            $table->string('participant', 80);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('played_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_chat_message_id', 'participant']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chat_receipts');
    }
};
