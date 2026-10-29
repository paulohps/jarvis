<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The state of a chat as WhatsApp keeps it: archived, marked unread,
     * muted (until when), the contact blocked, how long its messages last
     * (disappearing messages, in seconds) and, for a group, its description.
     */
    public function up(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('pinned_at');
            $table->boolean('marked_unread')->default(false)->after('unread_count');
            $table->timestamp('muted_until')->nullable()->after('archived_at');
            $table->timestamp('blocked_at')->nullable()->after('muted_until');
            $table->unsignedInteger('ephemeral_seconds')->nullable()->after('blocked_at');
            $table->text('description')->nullable()->after('nickname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_targets', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'marked_unread', 'muted_until', 'blocked_at', 'ephemeral_seconds', 'description']);
        });
    }
};
