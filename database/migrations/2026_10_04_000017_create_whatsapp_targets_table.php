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
        Schema::create('whatsapp_targets', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_target_type_id')->constrained();
            $table->string('jid');
            $table->string('name')->nullable();
            $table->boolean('is_synced')->default(true);
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'jid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_targets');
    }
};
