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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('whatsapp_message_whatsapp_target', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_target_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['whatsapp_message_id', 'whatsapp_target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_whatsapp_target');
        Schema::dropIfExists('whatsapp_messages');
    }
};
