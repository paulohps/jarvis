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
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->foreignId('shopping_list_schedule_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('is_default');
            $table->timestamp('completed_at')->nullable()->after('due_at');
            $table->foreignId('completed_by_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();

            $table->unique(['shopping_list_schedule_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropUnique(['shopping_list_schedule_id', 'due_at']);
            $table->dropConstrainedForeignId('completed_by_id');
            $table->dropColumn(['due_at', 'completed_at']);
            $table->dropConstrainedForeignId('shopping_list_schedule_id');
        });
    }
};
