<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery a message the AI wrote went out with, once the user sent
     * it, so that the sent message leads back to its conversation.
     */
    public function up(): void
    {
        Schema::table('whatsapp_draft_messages', function (Blueprint $table) {
            $table->foreignId('whatsapp_delivery_id')->nullable()->after('edited_at')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_draft_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_delivery_id');
        });
    }
};
