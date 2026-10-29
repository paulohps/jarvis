<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The proxy each number's WhatsApp connection goes out through (the
 * Evolution API's per-instance proxy), e.g. a SOCKS5 at home over a VPN so
 * the number keeps a residential IP. All null: a direct connection. The
 * password is stored encrypted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('proxy_protocol', 8)->nullable()->after('chats_synced_at');
            $table->string('proxy_host')->nullable()->after('proxy_protocol');
            $table->unsignedInteger('proxy_port')->nullable()->after('proxy_host');
            $table->string('proxy_username')->nullable()->after('proxy_port');
            $table->text('proxy_password')->nullable()->after('proxy_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['proxy_protocol', 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password']);
        });
    }
};
