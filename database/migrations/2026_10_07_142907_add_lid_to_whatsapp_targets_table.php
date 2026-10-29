<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `@lid` JID WhatsApp also knows a contact by, learned from its
     * messages: presence updates may come with it instead of the phone JID.
     */
    public function up(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->string('lid', 64)->nullable()->after('jid');
            $table->index(['whatsapp_account_id', 'lid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_account_id', 'lid']);
            $table->dropColumn('lid');
        });
    }
};
