<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a message the contact sends reaches the apps by push: on by
     * default per number, and each chat may differ from its number (null
     * follows it).
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('notifies_messages')->default(true)->after('sends_typing');
        });

        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->boolean('notifies_messages')->nullable()->after('sends_typing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropColumn('notifies_messages');
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('notifies_messages');
        });
    }
};
