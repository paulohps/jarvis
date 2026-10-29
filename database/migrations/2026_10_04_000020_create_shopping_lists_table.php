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
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->string('name', 100);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('quantity_note', 255)->nullable();
            $table->integer('position')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('client_updated_at')->nullable();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['shopping_list_id', 'checked_at', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
