<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each model of a provider costs (`ai_model_prices`), in US dollars per
 * million tokens, as the super admin types it from the provider's price
 * page: the usage report estimates the spend from it. A model without a
 * price shows its tokens only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained();
            $table->string('model');
            $table->decimal('input_per_million', 10, 4);
            $table->decimal('output_per_million', 10, 4);
            $table->decimal('cache_read_per_million', 10, 4)->nullable();
            $table->decimal('cache_write_per_million', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['ai_provider_id', 'model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
