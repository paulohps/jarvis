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
        Schema::create('recurrences', function (Blueprint $table) {
            $table->id();
            $table->string('schedulable_type');
            $table->unsignedBigInteger('schedulable_id');
            $table->foreignId('recurrence_frequency_id')->constrained();
            $table->time('time_of_day')->nullable();
            $table->smallInteger('day_of_month')->nullable();
            $table->boolean('is_last_day_of_month')->default(false);
            $table->string('cron_expression', 100)->nullable();
            $table->string('timezone', 64);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_paused')->default(false);
            $table->timestamps();

            $table->unique(['schedulable_type', 'schedulable_id']);
            $table->index(['is_paused', 'next_run_at']);
        });

        Schema::create('recurrence_weekday', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weekday_id')->constrained();
            $table->timestamps();

            $table->unique(['recurrence_id', 'weekday_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurrence_weekday');
        Schema::dropIfExists('recurrences');
    }
};
