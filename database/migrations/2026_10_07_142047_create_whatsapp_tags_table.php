<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's own tags for WhatsApp chats and groups.
     */
    public function up(): void
    {
        Schema::create('whatsapp_tags', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 16);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_tags');
    }
};
