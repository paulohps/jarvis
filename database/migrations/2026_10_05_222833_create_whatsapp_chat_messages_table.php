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
        Schema::create('whatsapp_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('whatsapp_target_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->boolean('from_me');
            $table->string('message_type', 64);
            $table->text('body')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['whatsapp_target_id', 'external_id']);
            $table->index(['whatsapp_target_id', 'from_me', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chat_messages');
    }
};
