<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call to the AI (`ai_usages`, written by RecordAiUsage): the
 * connection, use and user it went through, the provider and model that
 * answered and the tokens it took — input (cache reads and writes
 * included), output (reasoning included) — or the error when it failed.
 * The provider and model are copied, so the row keeps telling what was
 * spent after the connection is removed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->uuid('invocation_id')->unique();
            $table->foreignId('ai_provider_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_provider_id')->constrained();
            $table->foreignId('ai_purpose_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->boolean('succeeded');
            $table->string('error')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
