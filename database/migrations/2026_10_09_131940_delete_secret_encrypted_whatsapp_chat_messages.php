<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The encrypted envelopes of edits and of answers to events
 * (`secretEncryptedMessage`, `encEventResponseMessage`) were kept as
 * messages and showed as `[secretEncryptedMessage]`: they carry nothing to
 * show, so they go.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('whatsapp_chat_messages')
            ->whereIn('message_type', ['secretEncryptedMessage', 'encEventResponseMessage'])
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
