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
        Schema::create('google_calendars', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('google_calendar_account_id')->constrained()->cascadeOnDelete();
            $table->string('google_id');
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['google_calendar_account_id', 'google_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_calendars');
    }
};
