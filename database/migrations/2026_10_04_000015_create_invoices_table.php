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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('invoice_recurrence_id')->nullable()->constrained();
            $table->string('period_key', 32)->nullable();
            $table->string('number', 12)->unique();
            $table->char('number_year_month', 6);
            $table->integer('number_sequence');
            $table->foreignId('invoice_status_id')->index()->constrained();
            $table->date('issue_date');
            $table->date('due_date');
            $table->foreignId('currency_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->text('service_description');
            $table->string('email_subject', 512);
            $table->text('email_body');
            $table->foreignId('payer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_name', 255);
            $table->text('payer_address');
            $table->foreignId('issuer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('issuer_name', 255);
            $table->string('issuer_email');
            $table->string('issuer_postal_code', 20);
            $table->string('issuer_street');
            $table->string('issuer_district');
            $table->string('issuer_city');
            $table->string('issuer_state', 50);
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['invoice_recurrence_id', 'period_key']);
            $table->unique(['number_year_month', 'number_sequence']);
        });

        Schema::create('invoice_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();

            $table->unique(['invoice_id', 'email']);
        });

        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_status_id')->constrained();
            $table->foreignId('triggered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_deliveries');
        Schema::dropIfExists('invoice_recipients');
        Schema::dropIfExists('invoices');
    }
};
