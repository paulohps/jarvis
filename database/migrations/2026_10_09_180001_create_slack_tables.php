<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Slack provider of the chat layer: a user's Slack workspace, connected
 * by OAuth with a user token; its people, the conversations the user is in
 * (DMs, group DMs, public and private channels) and their messages, kept
 * as the Events API reports them and as the history is loaded.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('slack_accounts', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('team_id', 20);
            $table->string('team_name', 100);
            $table->string('team_domain', 100)->nullable();
            $table->string('slack_user_id', 20);
            $table->text('access_token');
            $table->json('scopes')->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
            $table->unique(['team_id', 'slack_user_id']);
        });

        Schema::create('slack_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slack_account_id')->constrained()->cascadeOnDelete();
            $table->string('slack_user_id', 20);
            $table->string('name', 100)->nullable();
            $table->string('real_name', 200)->nullable();
            $table->string('display_name', 200)->nullable();
            $table->text('picture_url')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['slack_account_id', 'slack_user_id']);
        });

        Schema::create('slack_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slack_account_id')->constrained()->cascadeOnDelete();
            $table->string('channel_id', 20);
            $table->string('kind', 20);
            $table->string('name', 200)->nullable();
            $table->text('topic')->nullable();
            $table->string('counterpart_user_id', 20)->nullable();
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_member')->default(true);
            $table->string('last_read', 30)->nullable();
            $table->timestamp('history_loaded_at')->nullable();
            $table->timestamp('history_exhausted_at')->nullable();
            $table->timestamps();

            $table->unique(['slack_account_id', 'channel_id']);
        });

        Schema::create('slack_messages', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('slack_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('ts', 30);
            $table->string('thread_ts', 30)->nullable();
            $table->string('slack_user_id', 20)->nullable();
            $table->string('bot_id', 20)->nullable();
            $table->string('subtype', 40)->nullable();
            $table->text('text')->nullable();
            $table->json('files')->nullable();
            $table->json('reactions')->nullable();
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['slack_conversation_id', 'ts']);
            $table->index(['slack_conversation_id', 'thread_ts']);
            $table->index(['slack_conversation_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_messages');
        Schema::dropIfExists('slack_conversations');
        Schema::dropIfExists('slack_users');
        Schema::dropIfExists('slack_accounts');
    }
};
