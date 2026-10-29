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
        Schema::create('invoice_recurrences', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->string('name', 100);
            $table->foreignId('payer_id')->constrained();
            $table->foreignId('issuer_id')->constrained();
            $table->foreignId('currency_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->text('service_description');
            $table->string('email_subject', 512);
            $table->text('email_body');
            $table->foreignId('invoice_date_rule_id')->constrained();
            $table->smallInteger('invoice_date_day')->nullable();
            $table->foreignId('invoice_due_date_rule_id')->constrained();
            $table->smallInteger('due_date_value')->nullable();
            $table->foreignId('invoice_delivery_mode_id')->constrained();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_recurrence_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_recurrence_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();

            $table->unique(['invoice_recurrence_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_recurrence_recipients');
        Schema::dropIfExists('invoice_recurrences');
    }
};
