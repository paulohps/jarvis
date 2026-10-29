<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI is used for (`ai_purposes`, a lookup seeded by LookupSeeder)
 * and, per use, the connection and model picked for it
 * (`ai_purpose_assignments`). A use without one takes the connection in use.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_purposes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('ai_purpose_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_purpose_id')->unique()->constrained();
            $table->foreignId('ai_provider_connection_id')->constrained()->cascadeOnDelete();
            $table->string('model')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_purpose_assignments');
        Schema::dropIfExists('ai_purposes');
    }
};
