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
        Schema::create('shopping_list_schedules', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->string('name', 100);
            $table->string('next_list_content', 20)->default('pending');
            $table->boolean('closes_automatically')->default(true);
            $table->boolean('waits_for_close')->default(false);
            $table->json('template_items')->nullable();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopping_list_schedules');
    }
};
