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
        Schema::create('issuers', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->string('name', 255);
            $table->string('email');
            $table->string('postal_code', 20);
            $table->string('street');
            $table->string('district');
            $table->string('city');
            $table->string('state', 50);
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
        Schema::dropIfExists('issuers');
    }
};
