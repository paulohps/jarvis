<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The language an invoice e-mail is written in (month names, dates, the
 * currency spelled out), picked per recurrence and copied to each invoice,
 * instead of the sender's interface language. Existing rows are written in
 * English, like the PDF.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $englishId = DB::table('locales')->where('slug', 'en')->value('id');

        foreach (['invoice_recurrences', 'invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('locale_id')->nullable()->after('mail_service_id')->constrained('locales');
            });

            if ($englishId !== null) {
                DB::table($tableName)->update(['locale_id' => $englishId]);
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('locale_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['invoices', 'invoice_recurrences'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('locale_id');
            });
        }
    }
};
