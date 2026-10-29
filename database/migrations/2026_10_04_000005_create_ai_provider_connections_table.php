<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_provider_connections', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('ai_provider_id')->constrained();
            $table->string('name', 100);
            $table->string('model');
            $table->text('api_key')->nullable();
            $table->string('api_key_last_four', 4)->nullable();
            $table->string('base_url')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX ai_provider_connections_single_active_unique ON ai_provider_connections (is_active) WHERE is_active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_connections');
    }
};
