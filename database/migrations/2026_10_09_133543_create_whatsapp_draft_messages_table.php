<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the user asked and what the AI wrote in a conversation about a
     * WhatsApp chat, the AI's as the user left it after editing it.
     */
    public function up(): void
    {
        Schema::create('whatsapp_draft_messages', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('whatsapp_draft_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_draft_messages');
    }
};
