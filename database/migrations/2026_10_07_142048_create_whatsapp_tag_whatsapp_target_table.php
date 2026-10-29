<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tags each chat or group has.
     */
    public function up(): void
    {
        Schema::create('whatsapp_tag_whatsapp_target', function (Blueprint $table) {
            $table->foreignId('whatsapp_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_target_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['whatsapp_tag_id', 'whatsapp_target_id']);
            $table->index('whatsapp_target_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_tag_whatsapp_target');
    }
};
