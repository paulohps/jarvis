<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A price removed in the usage report is only marked deleted, so the
     * usage of before its removal keeps its cost.
     */
    public function up(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
