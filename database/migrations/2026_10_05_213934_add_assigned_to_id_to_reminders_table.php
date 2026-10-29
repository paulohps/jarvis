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
        Schema::table('reminders', function (Blueprint $table) {
            $table->foreignId('assigned_to_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
        });

        DB::table('reminders')->update(['assigned_to_id' => DB::raw('created_by_id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_id');
        });
    }
};
