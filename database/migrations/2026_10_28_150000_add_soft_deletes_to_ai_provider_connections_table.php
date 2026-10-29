<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A connection removed in the admin area is only marked deleted, so the
     * AI usage it served keeps pointing at it.
     */
    public function up(): void
    {
        Schema::table('ai_provider_connections', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_provider_connections', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
