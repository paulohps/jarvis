<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The models a connection's key offers (`models`), picked when a use of the
 * AI goes through it; `model` stays the connection's default, one of them.
 * The connections already stored offer their default and, when a use
 * picked another model on them, that one too.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_provider_connections', function (Blueprint $table) {
            $table->json('models')->nullable()->after('model');
        });

        DB::table('ai_provider_connections')->orderBy('id')->each(function (object $connection): void {
            $picked = DB::table('ai_purpose_assignments')
                ->where('ai_provider_connection_id', $connection->id)
                ->whereNotNull('model')
                ->pluck('model')
                ->all();

            DB::table('ai_provider_connections')->where('id', $connection->id)->update([
                'models' => json_encode(array_values(array_unique([$connection->model, ...$picked]))),
            ]);
        });

        Schema::table('ai_provider_connections', function (Blueprint $table) {
            $table->json('models')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_provider_connections', function (Blueprint $table) {
            $table->dropColumn('models');
        });
    }
};
