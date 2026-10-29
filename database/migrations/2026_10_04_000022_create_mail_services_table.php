<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The services e-mail goes out through (SMTP, a Google account over the
 * Gmail API, Resend): each one owned by whoever created it, and offered to
 * everyone when the super admin marks it global. Notification types (their
 * e-mail channel) and invoice recurrences may pick one; without it the
 * application's default mailer sends.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mail_services', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('mail_service_type_id')->constrained();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('encryption', 10)->nullable();
            $table->string('username')->nullable();
            $table->text('secret')->nullable();
            $table->string('secret_last_four', 4)->nullable();
            $table->boolean('is_global')->default(false)->index();
            $table->timestamps();
        });

        Schema::table('notification_type_channels', function (Blueprint $table) {
            $table->foreignId('mail_service_id')->nullable()->after('is_enabled_by_default')->constrained()->nullOnDelete();
        });

        Schema::table('invoice_recurrences', function (Blueprint $table) {
            $table->foreignId('mail_service_id')->nullable()->after('invoice_delivery_mode_id')->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('mail_service_id')->nullable()->after('issuer_state')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_service_id');
        });

        Schema::table('invoice_recurrences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_service_id');
        });

        Schema::table('notification_type_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_service_id');
        });

        Schema::dropIfExists('mail_services');
        Schema::dropIfExists('mail_service_types');
    }
};
