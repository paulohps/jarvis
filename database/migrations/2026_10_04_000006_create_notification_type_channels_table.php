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
        Schema::create('notification_type_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_enabled_by_default')->default(true);
            $table->timestamps();

            $table->unique(['notification_type_id', 'notification_channel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_type_channels');
    }
};
