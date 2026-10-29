<?php

namespace App\Services\Chats;

/**
 * What a chat provider supports, so that the screens and the API offer
 * only what works on each conversation (Key Concept "Chat channel"): a
 * driver answers it per conversation (ChatProviderDriver::capabilities()),
 * since a Slack channel that was archived, or an account whose token was
 * revoked, cannot be written to.
 *
 * Everything Jarvis adds on its own — pin, priority, nickname, tags, the
 * contact link, reminders, scheduled messages, the AI draft — needs no
 * capability: it works on every conversation.
 */
final readonly class ChatCapabilities
{
    /**
     * @param  bool  $send  texts can be sent
     * @param  bool  $files  files can be attached to what is sent
     * @param  bool  $threads  messages have threads (replies under a message)
     * @param  bool  $reactions  emoji reactions can be read and sent
     * @param  list<string>|null  $reactionEmojis  the emojis the provider takes as reactions; null for any
     * @param  bool  $editMessages  the user's own messages can be edited
     * @param  bool  $deleteMessages  the user's own messages can be deleted
     * @param  bool  $mentions  people can be mentioned with `@`
     * @param  bool  $markRead  reading in Jarvis is told to the provider
     * @param  bool  $readReceipts  the provider says when others read
     * @param  bool  $typing  "typing…" goes to and comes from the provider
     * @param  bool  $presence  online / last seen is known
     * @param  bool  $history  older messages can be loaded from the provider
     * @param  bool  $search  the provider searches messages
     * @param  bool  $externalLink  the conversation opens on the provider
     * @param  string  $formatting  the markup of the texts: `mrkdwn` (Slack), `whatsapp`, `plain`
     * @param  bool  $polls  polls can be sent (OutgoingChatMessage::$poll)
     */
    public function __construct(
        public bool $send = false,
        public bool $files = false,
        public bool $threads = false,
        public bool $reactions = false,
        public ?array $reactionEmojis = null,
        public bool $editMessages = false,
        public bool $deleteMessages = false,
        public bool $mentions = false,
        public bool $markRead = false,
        public bool $readReceipts = false,
        public bool $typing = false,
        public bool $presence = false,
        public bool $history = false,
        public bool $search = false,
        public bool $externalLink = false,
        public string $formatting = 'plain',
        public bool $polls = false,
    ) {}

    /**
     * The same, read only: nothing can be sent or changed.
     */
    public function readOnly(): self
    {
        return new self(
            send: false,
            files: false,
            threads: $this->threads,
            reactions: false,
            reactionEmojis: $this->reactionEmojis,
            editMessages: false,
            deleteMessages: false,
            mentions: $this->mentions,
            markRead: false,
            readReceipts: $this->readReceipts,
            typing: false,
            presence: $this->presence,
            history: $this->history,
            search: $this->search,
            externalLink: $this->externalLink,
            formatting: $this->formatting,
            polls: false,
        );
    }

    /**
     * For the API and the screens.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'send' => $this->send,
            'files' => $this->files,
            'threads' => $this->threads,
            'reactions' => $this->reactions,
            'reaction_emojis' => $this->reactionEmojis,
            'edit_messages' => $this->editMessages,
            'delete_messages' => $this->deleteMessages,
            'mentions' => $this->mentions,
            'mark_read' => $this->markRead,
            'read_receipts' => $this->readReceipts,
            'typing' => $this->typing,
            'presence' => $this->presence,
            'history' => $this->history,
            'search' => $this->search,
            'external_link' => $this->externalLink,
            'formatting' => $this->formatting,
            'polls' => $this->polls,
        ];
    }
}
