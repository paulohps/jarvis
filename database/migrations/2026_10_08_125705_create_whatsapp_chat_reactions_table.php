<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The emoji each person reacted to a chat message with: one per person
     * (`reactor`: their JID, or `me` for the owner of the number).
     */
    public function up(): void
    {
        Schema::create('whatsapp_chat_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_chat_message_id')->constrained()->cascadeOnDelete();
            $table->string('reactor', 80);
            $table->string('emoji', 32);
            $table->timestamp('reacted_at');
            $table->timestamps();

            $table->unique(['whatsapp_chat_message_id', 'reactor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chat_reactions');
    }
};
