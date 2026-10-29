<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each sending of a scheduled message of the chat screen, sent or
     * failed: the history of the sendings. One per occurrence (`slot_at`),
     * so an occurrence is never sent twice; kept when the message or the
     * conversation goes (with the conversation's name as it was).
     */
    public function up(): void
    {
        Schema::create('chat_scheduled_deliveries', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_scheduled_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('conversation_name', 200)->nullable();
            $table->timestamp('slot_at');
            $table->text('body')->nullable();
            $table->string('status', 10);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['chat_scheduled_message_id', 'slot_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_scheduled_deliveries');
    }
};
