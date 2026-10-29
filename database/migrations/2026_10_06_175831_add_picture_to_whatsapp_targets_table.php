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
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->text('picture_url')->nullable()->after('priority_color');
            $table->timestamp('picture_checked_at')->nullable()->after('picture_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropColumn(['picture_url', 'picture_checked_at']);
        });
    }
};
