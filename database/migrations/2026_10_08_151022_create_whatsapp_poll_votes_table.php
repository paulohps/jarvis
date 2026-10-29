<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who voted for what in a poll: one row per person (`voter`: their JID,
     * or `me` for the owner of the number), with the options picked (none
     * when the vote was taken back).
     */
    public function up(): void
    {
        Schema::create('whatsapp_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_chat_message_id')->constrained()->cascadeOnDelete();
            $table->string('voter', 80);
            $table->json('options');
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['whatsapp_chat_message_id', 'voter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_poll_votes');
    }
};
