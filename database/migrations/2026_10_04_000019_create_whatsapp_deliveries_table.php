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
        Schema::create('whatsapp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_delivery_source_id')->constrained();
            $table->foreignId('delivery_status_id')->constrained();
            $table->text('body');
            $table->timestamp('scheduled_for')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->string('external_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_message_id', 'whatsapp_target_id', 'scheduled_for']);
            $table->index(['whatsapp_account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_deliveries');
    }
};
