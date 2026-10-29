<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chat layer over every messaging provider (Key Concept "Chat
 * channel"): the providers as a lookup, switched on per workspace and per
 * member, and the rows Jarvis keeps about a conversation whatever its
 * provider — the list entry (`chat_conversations`, pointing at the
 * provider's own row through `conversable`), the user's tags and contacts
 * (a person across providers), the reminders and the scheduled messages.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_provider_workspace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enabled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'chat_provider_id']);
        });

        Schema::create('chat_provider_workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_user_id')->constrained('workspace_user')->cascadeOnDelete();
            $table->foreignId('chat_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enabled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_user_id', 'chat_provider_id']);
        });

        Schema::create('chat_contacts', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        Schema::create('chat_tags', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 16);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_provider_id')->constrained()->cascadeOnDelete();
            $table->string('conversable_type', 40);
            $table->unsignedBigInteger('conversable_id');
            $table->foreignId('chat_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('name', 200)->nullable();
            $table->text('picture_url')->nullable();
            $table->string('nickname', 100)->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->unsignedTinyInteger('priority')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('marked_unread')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->json('last_message')->nullable();
            $table->timestamps();

            $table->unique(['conversable_type', 'conversable_id']);
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('chat_conversation_tag', function (Blueprint $table) {
            $table->foreignId('chat_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['chat_tag_id', 'chat_conversation_id']);
        });

        Schema::create('chat_conversation_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('message_id', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('chat_scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('thread_id', 60)->nullable();
            $table->timestamp('send_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['send_at', 'sent_at', 'cancelled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_scheduled_messages');
        Schema::dropIfExists('chat_conversation_reminders');
        Schema::dropIfExists('chat_conversation_tag');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('chat_tags');
        Schema::dropIfExists('chat_contacts');
        Schema::dropIfExists('chat_provider_workspace_user');
        Schema::dropIfExists('chat_provider_workspace');
        Schema::dropIfExists('chat_providers');
    }
};
