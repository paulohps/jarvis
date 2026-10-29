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
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 100);
            $table->string('instance_name', 100)->unique();
            $table->string('phone_number', 20)->nullable();
            $table->foreignId('whatsapp_connection_status_id')->constrained();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('whatsapp_notification_account_id')
                ->nullable()
                ->after('is_super_admin')
                ->constrained('whatsapp_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_notification_account_id');
        });

        Schema::dropIfExists('whatsapp_accounts');
    }
};
