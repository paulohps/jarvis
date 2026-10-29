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
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('archived_at');
        });

        DB::statement('CREATE UNIQUE INDEX shopping_lists_single_default_unique ON shopping_lists (is_default) WHERE is_default');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shopping_lists_single_default_unique');

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
