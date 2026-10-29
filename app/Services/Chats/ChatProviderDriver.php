<?php

namespace App\Services\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What every messaging provider of the chat layer does (Key Concept "Chat
 * channel"): says what it supports (capabilities()), lists the user's
 * accounts on it, reads and writes the messages of a ChatConversation
 * whose `conversable` is its own row, and keeps that row's list entry
 * (name, picture, unread count, last message) up to date as it hears from
 * the provider. The screens and the API never talk to a provider but
 * through its driver, found by slug in ChatProviderRegistry.
 *
 * A driver answers with the DTOs of this namespace, never with its own
 * models, and throws ChatProviderException when the provider refuses.
 */
interface ChatProviderDriver
{
    /**
     * The ChatProvider slug.
     */
    public function slug(): string;

    /**
     * What the provider supports on the conversation, or in general.
     */
    public function capabilities(?ChatConversation $conversation = null): ChatCapabilities;

    /**
     * The user's accounts on the provider.
     *
     * @return Collection<int, ChatAccountData>
     */
    public function accountsOf(User $user): Collection;

    /**
     * The messages of the conversation, oldest first: the latest `$limit`,
     * or the `$limit` before the message `$beforeId`. Thread replies stay
     * out, except the ones broadcast to the conversation.
     *
     * @return Collection<int, ChatMessageData>
     */
    public function messages(ChatConversation $conversation, ?string $beforeId, int $limit): Collection;

    /**
     * Whether there are messages before the oldest one given.
     */
    public function hasOlderMessages(ChatConversation $conversation, string $oldestId): bool;

    public function message(ChatConversation $conversation, string $messageId): ?ChatMessageData;

    /**
     * The message and the replies of its thread, oldest first.
     *
     * @return Collection<int, ChatMessageData>
     */
    public function thread(ChatConversation $conversation, string $messageId): Collection;

    /**
     * The people in the conversation, for mentions and names.
     *
     * @return Collection<int, ChatParticipantData>
     */
    public function participants(ChatConversation $conversation): Collection;

    /**
     * Sends the message as the user and returns it as recorded.
     *
     * @throws ChatProviderException
     */
    public function send(ChatConversation $conversation, OutgoingChatMessage $message): ChatMessageData;

    /**
     * Tells the provider the conversation was read up to its latest message.
     */
    public function markRead(ChatConversation $conversation): void;

    /**
     * Adds (`$on`) or removes the user's reaction.
     *
     * @throws ChatProviderException
     */
    public function react(ChatConversation $conversation, string $messageId, string $emoji, bool $on): void;

    /**
     * @throws ChatProviderException
     */
    public function edit(ChatConversation $conversation, string $messageId, string $text): ChatMessageData;

    /**
     * @throws ChatProviderException
     */
    public function delete(ChatConversation $conversation, string $messageId): void;

    /**
     * Brings the conversation in line with the provider: its name and
     * picture, and the latest messages (or older ones, when
     * `$olderThanId` is given).
     *
     * @throws ChatProviderException
     */
    public function refresh(ChatConversation $conversation, ?string $olderThanId = null): void;

    /**
     * The file of a message, as a response to stream, or null when the
     * message has no such file.
     *
     * @throws ChatProviderException
     */
    public function file(ChatConversation $conversation, string $messageId, string $fileId): ?ChatFileContents;

    /**
     * Up to `$limit` of the user's own recent messages in their other
     * conversations on the provider, for the AI to learn their style.
     *
     * @return list<string>
     */
    public function styleSamples(User $user, ChatConversation $except, int $limit): array;

    /**
     * The conversation on the provider itself.
     */
    public function externalUrl(ChatConversation $conversation): ?string;

    /**
     * Called before the user's own state of the entry changes (`$changes`
     * are the attributes about to be written: `pinned_at`, `archived_at`,
     * `muted_until`, `marked_unread`, `nickname`, `priority`), for a provider
     * that keeps that state on its own row too — WhatsApp keeps pins,
     * archives and the unread mark on the phone. A provider that keeps none
     * of it (Slack) does nothing. Throwing stops the change.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws ChatProviderException
     */
    public function entryChanged(ChatConversation $conversation, array $changes): void;
}
