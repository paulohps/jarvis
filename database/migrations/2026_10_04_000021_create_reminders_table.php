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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->string('title', 255);
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reminder_occurrences', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reminder_id', 'due_at']);
            $table->index(['completed_at', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_occurrences');
        Schema::dropIfExists('reminders');
    }
};
