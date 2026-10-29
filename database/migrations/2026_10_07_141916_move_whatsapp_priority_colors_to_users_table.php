<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The color of each chat priority becomes the user's choice, one per
     * priority, instead of one per chat.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('whatsapp_priority_colors')->nullable()->after('whatsapp_notification_phone');
        });

        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropColumn('priority_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->string('priority_color', 16)->nullable()->after('priority');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whatsapp_priority_colors');
        });
    }
};
