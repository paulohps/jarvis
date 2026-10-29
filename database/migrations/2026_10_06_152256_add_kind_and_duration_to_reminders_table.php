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
        Schema::table('reminders', function (Blueprint $table) {
            $table->string('kind', 20)->default('reminder')->after('note');
            $table->boolean('is_all_day')->default(false)->after('kind');
            $table->unsignedInteger('duration_minutes')->nullable()->after('is_all_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropColumn(['kind', 'is_all_day', 'duration_minutes']);
        });
    }
};
