<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's conversations with the AI about what to write in a WhatsApp
     * chat, named after what they asked.
     */
    public function up(): void
    {
        Schema::create('whatsapp_draft_conversations', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_target_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->boolean('reads_chat')->default(true);
            $table->json('reply_to')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_target_id', 'user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_draft_conversations');
    }
};
